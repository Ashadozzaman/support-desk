<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\KbArticle;
use Illuminate\Database\Eloquent\Collection;

readonly class ChatConversationData
{
    /**
     * @param  Collection<int, ChatMessage>  $messages  oldest first
     * @param  Collection<int, KbArticle>  $sources  keyed by article id
     */
    public function __construct(
        public ?ChatSession $session,
        public Collection $messages,
        public Collection $sources,
    ) {}
}
