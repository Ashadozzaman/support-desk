<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\MailboxRepositoryInterface;
use App\Enums\MailboxSyncStatus;
use App\Models\MailboxSetting;
use App\Models\MailboxSync;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class MailboxRepository implements MailboxRepositoryInterface
{
    private const COUNTERS = ['created_count', 'replied_count', 'skipped_count', 'duplicate_count', 'failed_count'];

    public function settings(): ?MailboxSetting
    {
        return MailboxSetting::query()->first();
    }

    public function saveSettings(array $attributes): MailboxSetting
    {
        $settings = $this->settings() ?? new MailboxSetting;
        $settings->fill($attributes)->save();

        return $settings;
    }

    public function startSync(?int $triggeredBy): MailboxSync
    {
        return MailboxSync::create(['triggered_by' => $triggeredBy, 'status' => MailboxSyncStatus::Running]);
    }

    public function updateSync(MailboxSync $sync, array $attributes): void
    {
        $sync->update($attributes);
    }

    public function incrementSyncCounter(int $syncId, string $column): void
    {
        if (! in_array($column, self::COUNTERS, true)) {
            throw new InvalidArgumentException("Unknown sync counter {$column}.");
        }

        MailboxSync::query()->whereKey($syncId)->increment($column);
    }

    public function recentSyncs(int $limit): Collection
    {
        return MailboxSync::query()
            ->with('triggeredBy:id,name')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
