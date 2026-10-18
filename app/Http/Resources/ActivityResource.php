<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ActivityEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\TicketActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TicketActivity $resource
 *
 * @mixin TicketActivity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'description' => $this->describe(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }

    private function describe(): string
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        return match ($this->event) {
            ActivityEvent::TicketCreated => 'Ticket created',
            ActivityEvent::TicketUpdated => 'Ticket details updated',
            ActivityEvent::TicketAssigned => 'Ticket assigned',
            ActivityEvent::TicketReassigned => 'Ticket reassigned',
            ActivityEvent::PriorityChanged => sprintf(
                'Priority changed from %s to %s',
                TicketPriority::from($old['priority'])->label(),
                TicketPriority::from($new['priority'])->label(),
            ),
            ActivityEvent::StatusChanged => sprintf(
                'Status changed from %s to %s',
                TicketStatus::from($old['status'])->label(),
                TicketStatus::from($new['status'])->label(),
            ),
            ActivityEvent::CategoryChanged => sprintf(
                'Category changed from %s to %s',
                $this->categoryLabel($old['category_id'] ?? null),
                $this->categoryLabel($new['category_id'] ?? null),
            ),
            ActivityEvent::ReplyAdded => 'Reply added',
            ActivityEvent::NoteAdded => 'Internal note added',
            ActivityEvent::AttachmentAdded => 'Attachment added',
            ActivityEvent::SlaWarning => 'SLA warning triggered',
            ActivityEvent::SlaBreached => 'SLA breached',
            ActivityEvent::TicketEscalated => 'Ticket escalated',
            ActivityEvent::TicketImported => 'Ticket imported',
            ActivityEvent::TicketReopened => 'Ticket reopened',
        };
    }

    private function categoryLabel(?int $categoryId): string
    {
        if ($categoryId === null) {
            return 'Uncategorised';
        }

        return Category::find($categoryId)?->name ?? 'Uncategorised';
    }
}
