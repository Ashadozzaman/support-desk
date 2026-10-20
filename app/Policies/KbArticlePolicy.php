<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\KbArticle;
use App\Models\User;

/**
 * Published help-centre articles are open to every signed-in user — they
 * are public help content, not ticket data. Drafts and all editing need
 * `knowledge-base.manage`.
 */
class KbArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KbArticle $article): bool
    {
        return $article->is_published || $user->can(Permission::KnowledgeBaseManage->value);
    }

    public function manage(User $user): bool
    {
        return $user->can(Permission::KnowledgeBaseManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::KnowledgeBaseManage->value);
    }

    public function update(User $user, KbArticle $article): bool
    {
        return $user->can(Permission::KnowledgeBaseManage->value);
    }

    public function delete(User $user, KbArticle $article): bool
    {
        return $user->can(Permission::KnowledgeBaseManage->value);
    }
}
