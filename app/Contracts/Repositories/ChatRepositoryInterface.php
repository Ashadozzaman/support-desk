<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\ChatAssistant;
use App\Enums\ChatMessageRole;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

interface ChatRepositoryInterface
{
    /**
     * Locks the customer's users row (FOR UPDATE) until the caller's
     * transaction ends, so two requests can't both create an active session.
     */
    public function lockOwner(User $user): void;

    public function activeSessionFor(User $user, ChatAssistant $assistant): ?ChatSession;

    public function createSession(User $user, ChatAssistant $assistant): ChatSession;

    /**
     * @return Collection<int, ChatMessage> oldest first
     */
    public function messages(ChatSession $session): Collection;

    /**
     * The newest `$limit` messages, returned oldest first.
     *
     * @return Collection<int, ChatMessage>
     */
    public function recentMessages(ChatSession $session, int $limit): Collection;

    /**
     * @param  list<int>  $kbArticleIds
     */
    public function addMessage(
        ChatSession $session,
        ChatMessageRole $role,
        string $content,
        array $kbArticleIds = [],
        bool $suggestsHandoff = false,
    ): ChatMessage;

    /**
     * Compare-and-set `active → handed_off`. True only for the one request
     * that made the change, so a double-click can't create two tickets.
     */
    public function markHandedOffIfActive(ChatSession $session): bool;

    public function attachTicket(ChatSession $session, int $ticketId): void;

    /**
     * The ticket of this user's most recent hand-off since `$since`, read
     * with a locking read so it sees a hand-off committed by a concurrent
     * request (a plain read would use this transaction's older snapshot).
     */
    public function latestHandedOffTicket(User $user, CarbonImmutable $since): ?Ticket;

    public function closeIfActive(ChatSession $session): void;

    /**
     * Help-chatbot sessions started in [from, to) per status, for the AI
     * report (the staff assistant has no hand-off, so no deflection).
     *
     * @return array<string, int> status value => count
     */
    public function sessionCountsBetween(CarbonImmutable $from, CarbonImmutable $to): array;
}
