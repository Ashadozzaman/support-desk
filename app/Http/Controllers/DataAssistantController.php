<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AskSupportChatRequest;
use App\Http\Resources\ChatMessageResource;
use App\Services\DataAssistantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff data assistant (Step 24b). As with the help chat, no route takes a
 * session id — it's always the signed-in user's own conversation. Access is
 * the route middleware (`permission:ai.use-assistant`, `ai.enabled`,
 * throttle); what each answer may contain is decided per lookup, as the
 * asking user, in DataAssistantTools.
 */
class DataAssistantController extends Controller
{
    public function __construct(private readonly DataAssistantService $assistant) {}

    public function show(Request $request): Response
    {
        return Inertia::render('assistant/index', [
            'messages' => ChatMessageResource::collection($this->assistant->conversation($request->user())->messages),
            'maxLength' => (int) config('ai.chat.max_message_length'),
        ]);
    }

    public function ask(AskSupportChatRequest $request): RedirectResponse
    {
        $this->assistant->ask($request->user(), $request->question());

        return redirect()->route('assistant.show');
    }

    public function startOver(Request $request): RedirectResponse
    {
        $this->assistant->startOver($request->user());

        return redirect()->route('assistant.show');
    }
}
