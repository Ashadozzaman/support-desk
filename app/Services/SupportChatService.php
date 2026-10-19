<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ChatRepositoryInterface;
use App\DTOs\AiRequest;
use App\DTOs\ChatConversationData;
use App\DTOs\CreateTicketData;
use App\Enums\AiFeature;
use App\Enums\ChatAssistant;
use App\Enums\ChatMessageRole;
use App\Enums\Permission;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Exceptions\AiProviderException;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\ChatNotActiveException;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\KbArticle;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer support chatbot (Step 24, ENHANCEMENTS.md §3 mode B): answers
 * from published help-centre articles only (retrieval-augmented), and hands
 * the conversation to a human as a normal ticket.
 *
 * What the model sees: our system prompt, the retrieved articles, and this
 * conversation — nothing else. No tickets, replies, internal notes or other
 * customers' data are ever put in the prompt, and the model has no tools,
 * so it can't change anything: every action (the hand-off) is a button the
 * customer presses, handled here without the model.
 *
 * The AI call happens outside any transaction: it can take seconds, and no
 * row lock may be held while waiting on a network call.
 */
class SupportChatService
{
    /** The model's signal (see the prompt) that the articles didn't answer the question. */
    private const NO_ANSWER_MARKER = '[NO_ANSWER]';

    private const NO_ARTICLES_REPLY = "I couldn't find a help-centre article about that, so I'd rather not guess. "
        .'Use "Talk to a human" and an agent will pick it up from here.';

    private const UNAVAILABLE_REPLY = "Sorry, I can't answer right now. "
        .'Use "Talk to a human" to turn this conversation into a ticket for an agent.';

    public function __construct(
        private readonly ChatRepositoryInterface $chats,
        private readonly KnowledgeBaseService $knowledgeBase,
        private readonly AiGuard $guard,
        private readonly TicketService $tickets,
        private readonly Filesystem $files,
    ) {}

    /** Shown on the help centre: the chat entry point, or "create a ticket" instead. */
    public function isAvailableFor(User $user): bool
    {
        return (bool) config('ai.enabled') && $user->can(Permission::AiChat->value);
    }

    public function conversation(User $customer): ChatConversationData
    {
        $session = $this->chats->activeSessionFor($customer, ChatAssistant::Support);
        $messages = $session ? $this->chats->messages($session) : new Collection;

        $articleIds = $messages->pluck('kb_article_ids')->filter()->flatten()->unique()->values()->all();

        return new ChatConversationData($session, $messages, $this->knowledgeBase->sources($articleIds));
    }

    /**
     * Stores the question, answers it, and returns the assistant's message.
     * Always returns a reply: when there is nothing to ground an answer in,
     * or the AI is unavailable, the reply says so and offers a human.
     */
    public function ask(User $customer, string $question): ChatMessage
    {
        $session = DB::transaction(function () use ($customer, $question) {
            $this->chats->lockOwner($customer);

            $session = $this->chats->activeSessionFor($customer, ChatAssistant::Support) ?? $this->chats->createSession($customer, ChatAssistant::Support);
            $this->chats->addMessage($session, ChatMessageRole::User, $question);

            return $session;
        });

        $history = $this->chats->recentMessages($session, (int) config('ai.chat.history_messages') + 1);

        $articles = $this->knowledgeBase->relevantArticles($this->retrievalQuery($history), (int) config('ai.chat.articles'));

        // Nothing to ground an answer in: don't ask the model to improvise.
        if ($articles->isEmpty()) {
            return $this->reply($session, self::NO_ARTICLES_REPLY, suggestsHandoff: true);
        }

        try {
            $response = $this->guard->complete($this->buildRequest($history, $articles), $customer);
        } catch (AiUnavailableException|AiProviderException) {
            return $this->reply($session, self::UNAVAILABLE_REPLY, suggestsHandoff: true);
        }

        if ($response->refused() || trim($response->text) === '') {
            return $this->reply($session, self::UNAVAILABLE_REPLY, suggestsHandoff: true);
        }

        $noAnswer = str_contains($response->text, self::NO_ANSWER_MARKER);
        $text = trim(str_replace(self::NO_ANSWER_MARKER, '', $response->text));

        return $this->reply(
            $session,
            $text,
            $noAnswer ? [] : $articles->pluck('id')->all(),
            $noAnswer,
        );
    }

    /**
     * Turns the open conversation into a ticket through the normal
     * TicketService (SLA, activity, auto-assign, notifications all apply).
     * The compare-and-set on the session runs first, inside the same
     * transaction as the ticket insert: of two simultaneous clicks only one
     * wins, and the other gets the winner's ticket instead of a second one.
     *
     * @throws ChatNotActiveException
     */
    public function handOff(User $customer): Ticket
    {
        return DB::transaction(function () use ($customer) {
            $session = $this->chats->activeSessionFor($customer, ChatAssistant::Support);

            if ($session === null) {
                return $this->existingHandOffTicket($customer);
            }

            $messages = $this->chats->messages($session);
            $firstQuestion = $messages->first(fn (ChatMessage $m) => $m->role === ChatMessageRole::User);

            if ($firstQuestion === null || ! $this->chats->markHandedOffIfActive($session)) {
                return $this->existingHandOffTicket($customer);
            }

            $ticket = $this->tickets->create(new CreateTicketData(
                subject: Str::limit('Chat: '.Str::squish($firstQuestion->content), 197),
                description: $this->transcript($messages),
                // No triage yet (Step 23): an agent adjusts the priority after reading it.
                priority: TicketPriority::Medium,
                categoryId: null,
                createdBy: $customer->id,
                source: TicketSource::Chat,
            ), $customer);

            $this->chats->attachTicket($session, $ticket->id);

            return $ticket;
        });
    }

    /** "New conversation": the old one ends without a ticket. */
    public function startOver(User $customer): void
    {
        $session = $this->chats->activeSessionFor($customer, ChatAssistant::Support);

        if ($session !== null) {
            $this->chats->closeIfActive($session);
        }
    }

    /**
     * A double-click lands here after the first click handed off: return
     * that ticket rather than an error.
     *
     * @throws ChatNotActiveException
     */
    private function existingHandOffTicket(User $customer): Ticket
    {
        $ticket = $this->chats->latestHandedOffTicket($customer, now()->subMinute());

        return $ticket ?? throw new ChatNotActiveException;
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function reply(ChatSession $session, string $text, array $articleIds = [], bool $suggestsHandoff = false): ChatMessage
    {
        return $this->chats->addMessage($session, ChatMessageRole::Assistant, $text, $articleIds, $suggestsHandoff);
    }

    /**
     * The new question plus the one before it, so a follow-up such as
     * "and how long does that take?" still finds the article it is about.
     *
     * @param  Collection<int, ChatMessage>  $history
     */
    private function retrievalQuery(Collection $history): string
    {
        return $history
            ->filter(fn (ChatMessage $m) => $m->role === ChatMessageRole::User)
            ->take(-2)
            ->pluck('content')
            ->implode("\n");
    }

    /**
     * @param  Collection<int, ChatMessage>  $history
     * @param  Collection<int, KbArticle>  $articles
     */
    private function buildRequest(Collection $history, Collection $articles): AiRequest
    {
        $numbered = $articles->values()->map(
            fn (KbArticle $article, int $i) => '['.($i + 1)."] {$article->title}\n{$article->body}",
        )->implode("\n\n");

        $system = trim($this->files->get(resource_path('prompts/support-chat.md')))
            ."\n\n".$this->guard->wrapUntrusted('articles', $numbered);

        // The Messages API wants the conversation to start with the user.
        $messages = $history
            ->skipUntil(fn (ChatMessage $m) => $m->role === ChatMessageRole::User)
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role->value,
                'content' => $m->role === ChatMessageRole::User
                    ? $this->guard->wrapUntrusted('customer_message', $m->content)
                    : $m->content,
            ])
            ->values()
            ->all();

        return new AiRequest(AiFeature::SupportChat, $system, $messages, (int) config('ai.chat.max_tokens'));
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     */
    private function transcript(Collection $messages): string
    {
        $lines = $messages->map(fn (ChatMessage $m) => ($m->role === ChatMessageRole::User ? 'Customer' : 'AI assistant')
            .': '.$m->content);

        return "The customer asked the AI help assistant first and then asked for a human. Conversation:\n\n"
            .$lines->implode("\n\n");
    }
}
