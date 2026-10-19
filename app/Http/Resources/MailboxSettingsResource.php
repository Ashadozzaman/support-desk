<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MailboxSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never carries the password — only where one comes from.
 *
 * @mixin MailboxSetting
 */
class MailboxSettingsResource extends JsonResource
{
    public function __construct(MailboxSetting $resource, private readonly ?string $passwordSource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'is_enabled' => $this->is_enabled,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption->value,
            'username' => $this->username,
            'folder' => $this->folder,
            'fetch_interval_minutes' => $this->fetch_interval_minutes,
            'batch_size' => $this->batch_size,
            'password_source' => $this->passwordSource,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
