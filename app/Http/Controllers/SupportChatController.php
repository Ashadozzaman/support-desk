<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AskSupportChatRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\KbArticle;
use App\Services\SupportChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's own conversation only: no route takes a session id, the
 * service always looks it up by the signed-in user, so there is no other
 * customer's chat to reach by changing a URL. Access is the route
 * middleware (`permission:ai.chat`, `ai.enabled`, throttle).
 */
class SupportChatController extends Controller
{
    public function __construct(private readonly SupportChatService $chat) {}

    public function show(Request $request): Response
    {
        $conversation = $this->chat->conversation($request->user());

        return Inertia::render('help/chat', [
            'messages' => ChatMessageResource::collection($conversation->messages),
            'sources' => $conversation->sources->map(fn (KbArticle $article) => [
                'title' => $article->title,
                'slug' => $article->slug,
            ]),
            'maxLength' => (int) config('ai.chat.max_message_length'),
        ]);
    }

    public function ask(AskSupportChatRequest $request): RedirectResponse
    {
        $this->chat->ask($request->user(), $request->question());

        return redirect()->route('help.chat.show');
    }

    public function handOff(Request $request): RedirectResponse
    {
        $ticket = $this->chat->handOff($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Ticket {$ticket->ticket_number} created — an agent will reply here."]);

        return redirect()->route('tickets.show', $ticket);
    }

    public function startOver(Request $request): RedirectResponse
    {
        $this->chat->startOver($request->user());

        return redirect()->route('help.chat.show');
    }
}
