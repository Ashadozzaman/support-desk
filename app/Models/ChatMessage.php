<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChatMessageRole;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['chat_session_id', 'role', 'content', 'kb_article_ids', 'suggests_handoff', 'created_at'])]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    // Append-only.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'role' => ChatMessageRole::class,
            'kb_article_ids' => 'array',
            'suggests_handoff' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChatSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
