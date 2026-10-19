<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\DTOs\IssueApiTokenData;
use App\Enums\AuditEvent;
use App\Exceptions\InvalidApiCredentialsException;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Personal access tokens for the REST API (docs/API.md). Same rules as the
 * login form: inactive and unverified accounts are refused, and two-factor
 * can't be bypassed by asking for a token instead of signing in.
 */
class ApiTokenService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogService $audit,
        private readonly Hasher $hasher,
        private readonly TwoFactorAuthenticationProvider $twoFactor,
    ) {}

    /**
     * @return string the plain-text token — shown once, only its hash is stored
     *
     * @throws InvalidApiCredentialsException
     */
    public function issue(IssueApiTokenData $data): string
    {
        $user = $this->users->findByEmail($data->email);

        // One generic message for unknown email, wrong password and inactive
        // account, so the endpoint can't be used to find out which emails exist.
        if ($user === null || ! $user->is_active || ! $this->hasher->check($data->password, $user->password)) {
            // Same event as a failed web login → security log + auth audit log.
            // Credentials carry the email only, never the password.
            event(new Failed('sanctum', $user, ['email' => $data->email]));

            throw new InvalidApiCredentialsException(__('auth.failed'), 'email');
        }

        if (! $user->hasVerifiedEmail()) {
            throw new InvalidApiCredentialsException('Verify your email address before requesting an API token.', 'email');
        }

        if ($user->hasEnabledTwoFactorAuthentication() && ! $this->validTwoFactorCode($user, $data->code)) {
            throw new InvalidApiCredentialsException('A valid two-factor authentication code is required.', 'code');
        }

        return DB::transaction(function () use ($user, $data) {
            $token = $this->users->createApiToken($user, $data->deviceName);

            $this->audit->record(AuditEvent::ApiTokenCreated, $user, $user, [
                'ip' => $data->ip,
                'user_agent' => $data->userAgent,
                'guard' => 'sanctum',
            ]);

            return $token;
        });
    }

    public function revokeCurrent(User $user, ?string $ip, ?string $userAgent): void
    {
        DB::transaction(function () use ($user, $ip, $userAgent) {
            $this->users->deleteCurrentApiToken($user);

            $this->audit->record(AuditEvent::ApiTokenRevoked, $user, $user, [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'guard' => 'sanctum',
            ]);
        });
    }

    private function validTwoFactorCode(User $user, ?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        return $this->twoFactor->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $code,
        );
    }
}
