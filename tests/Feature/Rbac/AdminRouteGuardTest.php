<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('every admin.* route carries the role:admin middleware', function () {
    $adminRoutes = collect(Route::getRoutes())->filter(
        fn ($route) => str_starts_with((string) $route->getName(), 'admin.')
    );

    expect($adminRoutes)->not->toBeEmpty();

    $adminRoutes->each(function ($route) {
        expect($route->gatherMiddleware())
            ->toContain('role:admin')
            ->toContain('auth')
            ->toContain('verified');
    });
});
