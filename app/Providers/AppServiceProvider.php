<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\Import;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Policies\AttachmentPolicy;
use App\Policies\BusinessHourPolicy;
use App\Policies\HolidayPolicy;
use App\Policies\ImportPolicy;
use App\Policies\ReplyPolicy;
use App\Policies\SlaPolicyPolicy;
use App\Policies\TicketPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePolicies();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! app()->isProduction());
        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Registered explicitly (rather than left to naming-convention
     * auto-discovery) so every model-policy pairing is visible in one place
     * — ReplyPolicy in particular doesn't match TicketReply's convention.
     */
    protected function configurePolicies(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(TicketReply::class, ReplyPolicy::class);
        Gate::policy(Attachment::class, AttachmentPolicy::class);
        Gate::policy(Import::class, ImportPolicy::class);
        Gate::policy(SlaPolicy::class, SlaPolicyPolicy::class);
        Gate::policy(BusinessHour::class, BusinessHourPolicy::class);
        Gate::policy(Holiday::class, HolidayPolicy::class);
    }
}
