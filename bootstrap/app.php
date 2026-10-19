<?php

use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\ProtectedRoleException;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Exceptions\UserLockoutException;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AssignRequestContext;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireHealthSecret;
use App\Support\Logging\LogRedactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Outside the `web` group on purpose: monitors have no session, CSRF
        // token or cookies, and polling every few seconds shouldn't create
        // sessions. Throttle runs first so the secret can't be brute-forced
        // and a leaked one can't be used to hammer the DB with checks.
        then: function (): void {
            Route::get('health', HealthController::class)
                ->middleware(['throttle:30,1', RequireHealthSecret::class])
                ->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            AssignRequestContext::class,
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Security log for real 403/429 responses (ENHANCEMENTS.md §2.1).
        // Not Gate::after: resources compute `can` flags through policies, so
        // every list page would log dozens of "denials" nobody attempted. By
        // now a policy's AuthorizationException is an AccessDeniedHttpException.
        // Login lockout is a 429 from Fortify's `throttle:login` middleware —
        // Fortify only fires the Lockout event when it throttles internally,
        // which it doesn't once a named limiter is configured.
        // Returning null keeps Laravel's normal rendering.
        $exceptions->render(function (HttpException $e, Request $request) {
            $route = $request->route()?->getName();

            match ($e->getStatusCode()) {
                403 => Log::channel('security')->notice('access.denied', [
                    'user_id' => $request->user()?->id,
                    'method' => $request->method(),
                    'route' => $route,
                    'path' => $request->path(),
                    'required_permissions' => $e instanceof UnauthorizedException ? $e->getRequiredPermissions() : null,
                ]),
                429 => Log::channel('security')->warning($route === 'login.store' ? 'auth.lockout' : 'request.throttled', [
                    'user_id' => $request->user()?->id,
                    'route' => $route,
                    'ip' => $request->ip(),
                    'email_hash' => $route === 'login.store' ? LogRedactor::email($request->string('email')->value()) : null,
                ]),
                default => null,
            };

            return null;
        });

        $exceptions->render(function (InvalidStatusTransitionException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        // A form error on the field that caused it, not a bare 422 page.
        $exceptions->map(UserLockoutException::class, fn (UserLockoutException $e) => ValidationException::withMessages([
            $e->field => $e->getMessage(),
        ]));
        $exceptions->map(ProtectedRoleException::class, fn (ProtectedRoleException $e) => ValidationException::withMessages([
            $e->field => $e->getMessage(),
        ]));

        $exceptions->render(function (TicketAlreadyAssignedException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
    })->create();
