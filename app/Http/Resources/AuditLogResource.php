<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * Only allow-listed properties leave the server; `email_hash` stays
 * internal (it is for correlating in SQL, not for display).
 *
 * @property-read Activity $resource
 *
 * @mixin Activity
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = AuditEvent::tryFrom((string) $this->event);
        $properties = $this->properties;

        return [
            'id' => $this->id,
            'log' => $this->log_name,
            'event' => $this->event,
            'label' => $event?->label() ?? $this->description,
            'causer' => $this->causer === null ? null : ['id' => $this->causer->id, 'name' => $this->causer->name],
            'subject' => $this->subject === null ? null : ['id' => $this->subject->id, 'name' => $this->subject->name],
            'ip' => $properties->get('ip'),
            'user_agent' => $properties->get('user_agent'),
            'remember' => $properties->get('remember'),
            'changes' => $properties->get('changes'),
            'holiday' => $properties->get('holiday'),
            'created_at' => $this->created_at,
        ];
    }
}
