<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Ticket $resource
 *
 * @mixin Ticket
 */
final class TicketListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'ticket_number' => $this->ticket_number,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn () => $this->assignedAgent === null ? null : [
                'id' => $this->assignedAgent->id,
                'name' => $this->assignedAgent->name,
            ]),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'sla_due_at' => $this->sla_due_at,
            'sla_warning_at' => $this->sla_warning_at,
            'created_at' => $this->created_at,
            'can' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => $user?->can('update', $this->resource) ?? false,
                'change_status' => $user?->can('changeStatus', $this->resource) ?? false,
                'claim' => $user?->can('claim', $this->resource) ?? false,
                'assign' => $user?->can('assign', $this->resource) ?? false,
            ],
        ];
    }
}
