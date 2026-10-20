<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\HelpCentreRequest;
use App\Http\Resources\KbArticleListResource;
use App\Http\Resources\KbArticleResource;
use App\Models\KbArticle;
use App\Services\KnowledgeBaseService;
use App\Services\SupportChatService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HelpCentreController extends Controller
{
    public function __construct(
        private readonly KnowledgeBaseService $knowledgeBase,
        private readonly SupportChatService $chat,
    ) {}

    public function index(HelpCentreRequest $request): Response
    {
        $this->authorize('viewAny', KbArticle::class);

        return Inertia::render('help/index', [
            'articles' => KbArticleListResource::collection($this->knowledgeBase->helpCentre($request->searchTerm())),
            'query' => $request->searchTerm() ?? '',
            // Cosmetic: the chat routes check the same thing (ai.enabled + ai.chat).
            'chatAvailable' => $this->chat->isAvailableFor($request->user()),
        ]);
    }

    /**
     * A draft 404s for anyone who can't manage the KB (not 403), so its
     * existence isn't revealed; an editor sees it to preview it.
     */
    public function show(Request $request, KbArticle $article): Response
    {
        abort_unless($request->user()->can('view', $article), 404);

        return Inertia::render('help/show', [
            'article' => KbArticleResource::make($this->knowledgeBase->forDisplay($article)),
        ]);
    }
}
