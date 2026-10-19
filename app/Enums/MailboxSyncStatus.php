<?php

declare(strict_types=1);

namespace App\Enums;

enum MailboxSyncStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    /** First sync (or a new mailbox): records where to start, imports nothing older. */
    case Baseline = 'baseline';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Baseline => 'Starting point set',
            self::Failed => 'Failed',
        };
    }
}
