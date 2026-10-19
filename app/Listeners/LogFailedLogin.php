<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Logging\LogRedactor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;

/**
 * Security log (ENHANCEMENTS.md §2.1). The event carries the submitted
 * credentials, password included — only the email is read, and only hashed.
 * Synchronous on purpose: it's one log line, and a queued listener would
 * serialize those credentials into the jobs table.
 */
class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        Log::channel('security')->warning('auth.login_failed', [
            'email_hash' => LogRedactor::email(is_string($email) ? $email : null),
            'ip' => request()->ip(),
            'guard' => $event->guard,
        ]);
    }
}
