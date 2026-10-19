<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

/**
 * Login pipeline step (FortifyServiceProvider): a deactivated account gets
 * the same "credentials do not match" error as a wrong password, before
 * the password is even checked, so the form doesn't reveal which accounts
 * exist or are disabled. The Failed event carries the user, so the audit
 * log still records which account was tried.
 */
class RejectInactiveUsers
{
    public function __construct(private readonly LoginRateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = User::query()->where('email', $request->string(Fortify::username())->value())->first();

        if ($user !== null && ! $user->is_active) {
            $this->limiter->increment($request);

            event(new Failed(config('fortify.guard'), $user, [
                Fortify::username() => $request->string(Fortify::username())->value(),
                'password' => $request->string('password')->value(),
            ]));

            throw ValidationException::withMessages([Fortify::username() => [trans('auth.failed')]]);
        }

        return $next($request);
    }
}
