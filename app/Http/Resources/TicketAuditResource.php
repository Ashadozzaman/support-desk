<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AuditEvent;
use App\Models\TicketActivity;
use Illuminate\Http\Request;

/**
 * A ticket_activities row in the audit page's row shape (Tickets log).
 * The human-readable line is ActivityResource's, so the audit page and the
 * ticket timeline can't describe the same event differently. Admin-only
 * page: no reply/note bodies are in ticket_activities to begin with.
 *
 * @property-read TicketActivity $resource
 *
 * @mixin TicketActivity
 */
class TicketAuditResource extends ActivityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activity = parent::toArray($request);

        return [
            'id' => $this->id,
            'log' => AuditEvent::LOG_TICKETS,
            'event' => $activity['event'],
            'label' => $activity['description'],
            'causer' => $this->actor === null ? null : ['id' => $this->actor->id, 'name' => $this->actor->name],
            'subject' => null,
            'ticket' => ['number' => $this->ticket->ticket_number, 'subject' => $this->ticket->subject],
            'ip' => null,
            'user_agent' => null,
            'remember' => null,
            'changes' => null,
            'holiday' => null,
            'created_at' => $this->created_at,
        ];
    }
}
