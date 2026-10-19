<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KbArticleRequest;
use App\Http\Resources\KbArticleListResource;
use App\Http\Resources\KbArticleResource;
use App\Models\KbArticle;
use App\Services\KnowledgeBaseService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class KbArticleController extends Controller
{
    public function __construct(private readonly KnowledgeBaseService $knowledgeBase) {}

    public function index(): Response
    {
        $this->authorize('manage', KbArticle::class);

        return Inertia::render('admin/kb-articles/index', [
            'articles' => KbArticleListResource::collection($this->knowledgeBase->adminList()),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', KbArticle::class);

        return Inertia::render('admin/kb-articles/create', [
            'categories' => $this->knowledgeBase->categoryOptions(),
        ]);
    }

    public function store(KbArticleRequest $request): RedirectResponse
    {
        $this->authorize('create', KbArticle::class);

        $this->knowledgeBase->create($request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Article created.']);

        return redirect()->route('admin.kb-articles.index');
    }

    public function edit(KbArticle $article): Response
    {
        $this->authorize('update', $article);

        return Inertia::render('admin/kb-articles/edit', [
            'article' => KbArticleResource::make($this->knowledgeBase->forDisplay($article)),
            'categories' => $this->knowledgeBase->categoryOptions(),
        ]);
    }

    public function update(KbArticleRequest $request, KbArticle $article): RedirectResponse
    {
        $this->authorize('update', $article);

        $this->knowledgeBase->update($article, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Article saved.']);

        return redirect()->route('admin.kb-articles.index');
    }

    public function destroy(KbArticle $article): RedirectResponse
    {
        $this->authorize('delete', $article);

        $this->knowledgeBase->delete($article);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Article deleted.']);

        return redirect()->route('admin.kb-articles.index');
    }
}
