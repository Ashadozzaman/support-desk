<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Logging\LogRedactor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Writes auth events to the database audit log (Step 19c). The security
 * *file* log (LogFailedLogin) stays as it is — that one is for log
 * shipping, this one is for the admin Audit log page.
 *
 * Synchronous on purpose: one small insert, and `Failed` carries the
 * submitted password, which a queued listener would serialize into `jobs`.
 * Listener methods are found by event discovery (handle* + type-hint).
 */
class RecordAuthAudit
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function handleLogin(Login $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::Login, $user, $user, [
            ...$this->client(),
            'guard' => $event->guard,
            'remember' => $event->remember,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::Logout, $user, $user, [...$this->client(), 'guard' => $event->guard]);
    }

    /**
     * No causer — nobody is authenticated. The subject is the account that
     * was tried, when the email matched one.
     */
    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        $this->audit->record(AuditEvent::LoginFailed, null, $this->user($event->user), [
            ...$this->client(),
            'guard' => $event->guard,
            'email_hash' => LogRedactor::email(is_string($email) ? $email : null),
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->audit->record(AuditEvent::Lockout, null, null, [
            ...$this->client(),
            'email_hash' => LogRedactor::email(is_string($email) ? $email : null),
        ]);
    }

    public function handleRegistered(Registered $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::Registered, $user, $user, $this->client());
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::PasswordReset, $user, $user, $this->client());
    }

    /**
     * With `confirm => true` (config/fortify.php) two-factor is only active
     * once the code is confirmed — `TwoFactorAuthenticationEnabled` fires
     * when setup merely starts, so it isn't recorded.
     */
    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::TwoFactorEnabled, $user, $user, $this->client());
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::TwoFactorDisabled, $user, $user, $this->client());
    }

    /**
     * @return array{ip: ?string, user_agent: ?string}
     */
    private function client(): array
    {
        return ['ip' => request()->ip(), 'user_agent' => request()->userAgent()];
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
