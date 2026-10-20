<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ChatRepositoryInterface;
use App\DTOs\AiRequest;
use App\DTOs\AiResponse;
use App\DTOs\ChatConversationData;
use App\Enums\AiFeature;
use App\Enums\ChatAssistant;
use App\Enums\ChatMessageRole;
use App\Enums\Permission;
use App\Exceptions\AiProviderException;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\DataToolException;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * Staff data assistant (Step 24b): answers questions such as "latest
 * tickets", "resolved today" or "last 7 days report" with Claude's tool use.
 *
 * The model never touches the database. It can only ask for one of the
 * read-only lookups in DataAssistantTools; this service runs each one *as
 * the person asking* and sends the result back, until the model answers or
 * `ai.data_assistant.max_rounds` is reached. Every round is one AiGuard call
 * (flag, breaker, rate limit, budget, redaction, audit row).
 *
 * Only the question and the final answer are stored — not the lookup
 * results — and, as in SupportChatService, no transaction is open while
 * the model is called.
 */
class DataAssistantService
{
    private const UNAVAILABLE_REPLY = "Sorry, I can't answer right now. The Tickets and Reports pages have the same numbers.";

    // Staff-facing (this assistant is staff-only), so they say what an admin
    // has to fix. The customer help chat keeps a neutral message instead.
    private const MISSING_KEY_REPLY = 'The AI is not set up: there is no API key. An admin needs to add %s to .env (then run php artisan config:clear).';

    private const INVALID_KEY_REPLY = 'The AI provider rejected the API key. An admin needs to check %s in .env.';

    private const NO_CREDIT_REPLY = 'The AI provider account has run out of credit. An admin needs to add API credit to the %s account (API billing is separate from a ChatGPT or Claude subscription), or check that %s belongs to that account.';

    private const BUDGET_REPLY = "Today's AI token budget for this app is used up (AI_DAILY_TOKEN_BUDGET). It resets at midnight, or an admin can raise it.";

    private const RATE_LIMITED_REPLY = "You're asking faster than the AI limit allows. Wait a minute and try again.";

    private const PAUSED_REPLY = 'AI calls are paused for a few minutes after repeated provider errors. If this keeps happening, an admin should check the API key and the account credit.';

    private const TOO_MANY_ROUNDS_REPLY = "I couldn't work that out in a few lookups. Try a narrower question, e.g. one date range or one status.";

    public function __construct(
        private readonly ChatRepositoryInterface $chats,
        private readonly DataAssistantTools $tools,
        private readonly AiGuard $guard,
        private readonly Filesystem $files,
    ) {}

    public function isAvailableFor(User $user): bool
    {
        return (bool) config('ai.enabled') && $user->can(Permission::AiUseAssistant->value);
    }

    public function conversation(User $user): ChatConversationData
    {
        $session = $this->chats->activeSessionFor($user, ChatAssistant::Data);

        return new ChatConversationData($session, $session ? $this->chats->messages($session) : new Collection, new Collection);
    }

    public function ask(User $user, string $question): ChatMessage
    {
        $session = DB::transaction(function () use ($user, $question) {
            $this->chats->lockOwner($user);

            $session = $this->chats->activeSessionFor($user, ChatAssistant::Data)
                ?? $this->chats->createSession($user, ChatAssistant::Data);
            $this->chats->addMessage($session, ChatMessageRole::User, $question);

            return $session;
        });

        $messages = $this->history($session);
        $system = $this->systemPrompt();
        $tools = $this->tools->definitions();

        for ($round = 1; $round <= (int) config('ai.data_assistant.max_rounds'); $round++) {
            try {
                $response = $this->guard->complete(
                    new AiRequest(AiFeature::DataAssistant, $system, $messages, (int) config('ai.max_tokens'), $tools),
                    $user,
                );
            } catch (AiProviderException $e) {
                return $this->reply($session, $this->providerProblemReply($e));
            } catch (AiUnavailableException $e) {
                return $this->reply($session, match ($e->reason) {
                    AiUnavailableException::BUDGET_EXCEEDED => self::BUDGET_REPLY,
                    AiUnavailableException::RATE_LIMITED => self::RATE_LIMITED_REPLY,
                    AiUnavailableException::CIRCUIT_OPEN => self::PAUSED_REPLY,
                    default => self::UNAVAILABLE_REPLY,
                });
            }

            if ($response->refused()) {
                return $this->reply($session, self::UNAVAILABLE_REPLY);
            }

            if (! $response->wantsTools()) {
                return $this->reply($session, trim($response->text) !== '' ? trim($response->text) : self::UNAVAILABLE_REPLY);
            }

            // The assistant turn goes back exactly as received (thinking
            // blocks included), followed by one user turn with every result.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $this->runTools($response, $user)];
        }

        return $this->reply($session, self::TOO_MANY_ROUNDS_REPLY);
    }

    private function providerProblemReply(AiProviderException $e): string
    {
        $provider = (string) config('ai.provider');
        $keyName = strtoupper($provider).'_API_KEY';

        return match ($e->problem()) {
            AiProviderException::MISSING_KEY => sprintf(self::MISSING_KEY_REPLY, $keyName),
            AiProviderException::INVALID_KEY => sprintf(self::INVALID_KEY_REPLY, $keyName),
            AiProviderException::NO_CREDIT => sprintf(self::NO_CREDIT_REPLY, $provider === 'openai' ? 'OpenAI' : 'Anthropic', $keyName),
            default => self::UNAVAILABLE_REPLY,
        };
    }

    public function startOver(User $user): void
    {
        $session = $this->chats->activeSessionFor($user, ChatAssistant::Data);

        if ($session !== null) {
            $this->chats->closeIfActive($session);
        }
    }

    /**
     * @return list<array<string, mixed>> tool_result blocks
     */
    private function runTools(AiResponse $response, User $user): array
    {
        return array_map(function (array $call) use ($user): array {
            try {
                $result = ['content' => (string) json_encode($this->tools->run($call['name'], $call['input'], $user))];
            } catch (DataToolException $e) {
                $result = ['content' => $e->getMessage(), 'is_error' => true];
            }

            return ['type' => 'tool_result', 'tool_use_id' => $call['id'], ...$result];
        }, $response->toolCalls);
    }

    /**
     * Earlier questions and answers (text only) for follow-ups like "and
     * yesterday?". The Messages API wants the conversation to start with the user.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(ChatSession $session): array
    {
        return $this->chats->recentMessages($session, (int) config('ai.chat.history_messages') + 1)
            ->skipUntil(fn (ChatMessage $m) => $m->role === ChatMessageRole::User)
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role->value,
                'content' => $m->role === ChatMessageRole::User
                    ? $this->guard->wrapUntrusted('staff_message', $m->content)
                    : $m->content,
            ])
            ->values()
            ->all();
    }

    /** Today's date is part of the prompt: the model has no clock of its own. */
    private function systemPrompt(): string
    {
        $timezone = (string) config('support.sla.timezone');
        $now = CarbonImmutable::now($timezone);

        return trim($this->files->get(resource_path('prompts/data-assistant.md')))
            ."\n\nToday is {$now->format('l, Y-m-d')} ({$timezone}); the time is {$now->format('H:i')}.";
    }

    private function reply(ChatSession $session, string $text): ChatMessage
    {
        return $this->chats->addMessage($session, ChatMessageRole::Assistant, $text);
    }
}
