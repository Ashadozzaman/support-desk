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
use Illuminate\Foundation\DevCommands;
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
        $this->configureDevCommands();
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

    /**
     * `composer dev` (→ `php artisan dev`) already runs serve/queue/vite via
     * Laravel's built-in dev-process registry (QUEUE_SCHEDULER_REALTIME.md
     * §6 asks for a bespoke `dev:all` script, but this starter kit ships
     * that registry instead, so escalation/broadcasting are added to it
     * here rather than duplicating it with `concurrently`).
     */
    protected function configureDevCommands(): void
    {
        // Overrides the framework default "queue" dev command, which only
        // listens on the "default" queue — our sla/notifications/broadcasts
        // jobs would otherwise never run under `composer dev`.
        DevCommands::artisan('queue:listen --queue=sla,default,notifications,broadcasts --tries=3', 'queue');
        DevCommands::artisan('schedule:work', 'schedule');
        DevCommands::artisan('reverb:start --debug', 'reverb');
    }
}
