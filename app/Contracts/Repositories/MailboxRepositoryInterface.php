<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\MailboxSetting;
use App\Models\MailboxSync;
use Illuminate\Database\Eloquent\Collection;

interface MailboxRepositoryInterface
{
    public function settings(): ?MailboxSetting;

    /**
     * Creates the single settings row, or updates it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveSettings(array $attributes): MailboxSetting;

    public function startSync(?int $triggeredBy): MailboxSync;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSync(MailboxSync $sync, array $attributes): void;

    /**
     * Atomic `+1` on one of the *_count columns — jobs finish in parallel.
     */
    public function incrementSyncCounter(int $syncId, string $column): void;

    /**
     * @return Collection<int, MailboxSync>
     */
    public function recentSyncs(int $limit): Collection;
}
