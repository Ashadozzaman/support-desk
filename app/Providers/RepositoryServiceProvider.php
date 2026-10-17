<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Repository interface => Eloquent implementation bindings are
        // added here as each repository is introduced (see CODING_STRUCTURE.md §5).
    }
}
