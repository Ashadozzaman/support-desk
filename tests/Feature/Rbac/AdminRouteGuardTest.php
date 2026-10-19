<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Admin routes are gated by permission, not by `role:admin` (Step 19e:
// roles are editable, so a custom role holding e.g. sla.manage must reach
// the SLA pages). This guards against a route being added without one.
test('every admin.* route requires auth, verified email and a permission', function () {
    $adminRoutes = collect(Route::getRoutes())->filter(
        fn ($route) => str_starts_with((string) $route->getName(), 'admin.')
    );

    expect($adminRoutes)->not->toBeEmpty();

    $adminRoutes->each(function ($route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('auth')->toContain('verified')
            ->and(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')))
            ->toBeTrue("{$route->getName()} has no permission: middleware");
    });
});

test('no route authorizes by role name', function () {
    collect(Route::getRoutes())->each(function ($route) {
        expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:')))
            ->toBeFalse("{$route->getName()} uses role: middleware");
    });
});
