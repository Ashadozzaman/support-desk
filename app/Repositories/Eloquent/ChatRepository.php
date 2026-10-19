<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ChatRepositoryInterface;
use App\Enums\ChatMessageRole;
use App\Enums\ChatSessionStatus;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class ChatRepository implements ChatRepositoryInterface
{
    public function lockOwner(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);
    }

    public function activeSessionFor(User $user): ?ChatSession
    {
        return ChatSession::query()
            ->where('user_id', $user->id)
            ->where('status', ChatSessionStatus::Active)
            ->latest('id')
            ->first();
    }

    public function createSession(User $user): ChatSession
    {
        return ChatSession::create([
            'user_id' => $user->id,
            'status' => ChatSessionStatus::Active,
        ]);
    }

    public function messages(ChatSession $session): Collection
    {
        return ChatMessage::query()
            ->where('chat_session_id', $session->id)
            ->orderBy('id')
            ->get();
    }

    public function recentMessages(ChatSession $session, int $limit): Collection
    {
        return ChatMessage::query()
            ->where('chat_session_id', $session->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public function addMessage(
        ChatSession $session,
        ChatMessageRole $role,
        string $content,
        array $kbArticleIds = [],
        bool $suggestsHandoff = false,
    ): ChatMessage {
        return ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'kb_article_ids' => $kbArticleIds === [] ? null : $kbArticleIds,
            'suggests_handoff' => $suggestsHandoff,
            'created_at' => now(),
        ]);
    }

    public function markHandedOffIfActive(ChatSession $session): bool
    {
        return ChatSession::query()
            ->whereKey($session->id)
            ->where('status', ChatSessionStatus::Active)
            ->update(['status' => ChatSessionStatus::HandedOff, 'updated_at' => now()]) === 1;
    }

    public function attachTicket(ChatSession $session, int $ticketId): void
    {
        ChatSession::query()->whereKey($session->id)->update(['ticket_id' => $ticketId]);
    }

    public function latestHandedOffTicket(User $user, CarbonImmutable $since): ?Ticket
    {
        $ticketId = ChatSession::query()
            ->where('user_id', $user->id)
            ->where('status', ChatSessionStatus::HandedOff)
            ->whereNotNull('ticket_id')
            ->where('updated_at', '>=', $since)
            ->latest('id')
            ->lockForUpdate()
            ->value('ticket_id');

        return $ticketId === null ? null : Ticket::query()->find($ticketId);
    }

    public function closeIfActive(ChatSession $session): void
    {
        ChatSession::query()
            ->whereKey($session->id)
            ->where('status', ChatSessionStatus::Active)
            ->update(['status' => ChatSessionStatus::Closed, 'updated_at' => now()]);
    }

    public function sessionCountsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ChatSession::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS sessions')
            ->toBase()
            ->pluck('sessions', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}
