<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Events in the security/admin audit log (`activity_log`, Step 19c). Ticket
 * events are NOT here — they live in `ticket_activities` (ActivityEvent),
 * so there is one audit trail per kind of thing, never two.
 *
 * The part before the dot is the spatie `log_name`, so a value can't be
 * filed under the wrong log.
 */
enum AuditEvent: string
{
    case Login = 'auth.login';
    case Logout = 'auth.logout';
    case LoginFailed = 'auth.login_failed';
    case Lockout = 'auth.lockout';
    case Registered = 'auth.registered';
    case PasswordReset = 'auth.password_reset';
    case PasswordChanged = 'auth.password_changed';
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorDisabled = 'auth.two_factor_disabled';
    case ApiTokenCreated = 'auth.api_token_created';
    case ApiTokenRevoked = 'auth.api_token_revoked';
    case SlaPoliciesUpdated = 'admin.sla_policies_updated';
    case BusinessHoursUpdated = 'admin.business_hours_updated';
    case HolidayAdded = 'admin.holiday_added';
    case HolidayRemoved = 'admin.holiday_removed';
    case UserCreated = 'admin.user_created';
    case UserUpdated = 'admin.user_updated';
    case RoleCreated = 'admin.role_created';
    case RoleUpdated = 'admin.role_updated';
    case RoleDeleted = 'admin.role_deleted';
    case MailboxSettingsUpdated = 'admin.mailbox_settings_updated';

    public const LOG_AUTH = 'auth';

    public const LOG_ADMIN = 'admin';

    /**
     * Read-only view of `ticket_activities` on the audit page — nothing is
     * ever written to activity_log under this name.
     */
    public const LOG_TICKETS = 'tickets';

    public function logName(): string
    {
        return Str::before($this->value, '.');
    }

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Logged in',
            self::Logout => 'Logged out',
            self::LoginFailed => 'Failed login',
            self::Lockout => 'Locked out (too many attempts)',
            self::Registered => 'Registered',
            self::PasswordReset => 'Password reset via email',
            self::PasswordChanged => 'Password changed',
            self::TwoFactorEnabled => 'Two-factor enabled',
            self::TwoFactorDisabled => 'Two-factor disabled',
            self::ApiTokenCreated => 'API token created',
            self::ApiTokenRevoked => 'API token revoked',
            self::SlaPoliciesUpdated => 'SLA policies updated',
            self::BusinessHoursUpdated => 'Business hours updated',
            self::HolidayAdded => 'Holiday added',
            self::HolidayRemoved => 'Holiday removed',
            self::UserCreated => 'User created',
            self::UserUpdated => 'User updated',
            self::RoleCreated => 'Role created',
            self::RoleUpdated => 'Role updated',
            self::RoleDeleted => 'Role deleted',
            self::MailboxSettingsUpdated => 'Mailbox settings updated',
        };
    }

    /**
     * Logs the audit page can show (`tickets` reads ticket_activities).
     *
     * @return list<string>
     */
    public static function logNames(): array
    {
        return [self::LOG_AUTH, self::LOG_ADMIN, self::LOG_TICKETS];
    }
}
