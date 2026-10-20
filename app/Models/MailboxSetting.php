<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailboxEncryption;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** The one support mailbox (docs/IMAP.md). */
#[Fillable([
    'is_enabled', 'host', 'port', 'encryption', 'username', 'password', 'folder',
    'fetch_interval_minutes', 'batch_size', 'last_uid', 'uid_validity', 'last_synced_at',
])]
#[Hidden(['password'])]
class MailboxSetting extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'port' => 'integer',
            'encryption' => MailboxEncryption::class,
            // Encrypted at rest with APP_KEY; never serialised (#[Hidden]).
            'password' => 'encrypted',
            'fetch_interval_minutes' => 'integer',
            'batch_size' => 'integer',
            'last_uid' => 'integer',
            'uid_validity' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
