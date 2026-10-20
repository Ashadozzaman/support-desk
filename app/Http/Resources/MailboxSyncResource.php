<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MailboxSync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MailboxSync
 */
class MailboxSyncResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'triggered_by' => $this->triggeredBy?->name,
            'fetched_count' => $this->fetched_count,
            'created_count' => $this->created_count,
            'replied_count' => $this->replied_count,
            'skipped_count' => $this->skipped_count,
            'duplicate_count' => $this->duplicate_count,
            'failed_count' => $this->failed_count,
            'error' => $this->error,
            'started_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
