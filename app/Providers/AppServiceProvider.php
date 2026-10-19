<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\Import;
use App\Models\KbArticle;
use App\Models\ReportExport;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BusinessHourPolicy;
use App\Policies\HolidayPolicy;
use App\Policies\ImportPolicy;
use App\Policies\KbArticlePolicy;
use App\Policies\MonitoringPolicy;
use App\Policies\ReplyPolicy;
use App\Policies\ReportExportPolicy;
use App\Policies\ReportPolicy;
use App\Policies\RolePolicy;
use App\Policies\SlaPolicyPolicy;
use App\Policies\TicketPolicy;
use App\Policies\UserPolicy;
use App\Support\Health\AiProviderCheck;
use App\Support\Health\FailedJobsCheck;
use App\Support\Health\QueueBacklogCheck;
use App\Support\Health\ReverbCheck;
use App\Support\Health\SlaBacklogCheck;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\Permission\Models\Role;

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
        $this->configureSlowQueryLog();
        $this->configureHealthChecks();
        $this->configureApi();
        $this->configureRateLimiting();
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
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(KbArticle::class, KbArticlePolicy::class);

        Gate::define('viewReports', [ReportPolicy::class, 'viewAny']);
        Gate::define('viewReportTab', [ReportPolicy::class, 'viewTab']);
        Gate::define('exportReports', [ReportPolicy::class, 'export']);
        Gate::policy(ReportExport::class, ReportExportPolicy::class);

        // `viewPulse` is the ability Laravel Pulse checks for /pulse.
        Gate::define('viewSystemHealth', [MonitoringPolicy::class, 'view']);
        Gate::define('viewPulse', [MonitoringPolicy::class, 'view']);
        Gate::define('viewAuditLog', [AuditLogPolicy::class, 'viewAny']);
    }

    /**
     * REST API (docs/API.md). A deactivated user's tokens stop working at
     * once — the API equivalent of EnsureUserIsActive ending web sessions.
     * The OpenAPI document declares bearer auth so the "Try it" panel at
     * /docs/api sends the token.
     */
    protected function configureApi(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && $token->tokenable instanceof User
                && $token->tokenable->is_active,
        );

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }

    /**
     * Chat questions (Step 24): per user, on top of AiGuard's own per-user
     * limits — this one rejects the HTTP request (429) before anything is
     * stored, so a script can't fill the chat tables either.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ai-chat', fn (Request $request) => Limit::perMinute((int) config('ai.chat.requests_per_minute'))
            ->by((string) $request->user()?->id));
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
        DevCommands::artisan('queue:listen --queue=sla,default,notifications,broadcasts,exports,ai --tries=3', 'queue');
        DevCommands::artisan('schedule:work', 'schedule');
        DevCommands::artisan('reverb:start --debug', 'reverb');
    }

    /**
     * Per-query slow log (ENHANCEMENTS.md §2.1). DB::listen rather than
     * DB::whenQueryingForLongerThan: the latter fires on the *total* query
     * time of a request/job, so it can't name the slow statement. Only the
     * SQL with its `?` placeholders is logged — bindings can carry ticket
     * text or emails.
     */
    protected function configureSlowQueryLog(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if ($query->time < (int) config('support.monitoring.slow_query_ms')) {
                return;
            }

            Log::channel('performance')->warning('query.slow', [
                'sql' => $query->sql,
                'ms' => round($query->time, 1),
                'connection' => $query->connectionName,
            ]);
        });
    }

    /**
     * Health checks behind GET /health and the System health page
     * (ENHANCEMENTS.md §2.2). Schedule/Queue read heartbeats written by
     * `health:schedule-check-heartbeat` / `health:queue-check-heartbeat`
     * (routes/console.php) — a stale heartbeat means the scheduler or that
     * queue's worker has stopped.
     */
    protected function configureHealthChecks(): void
    {
        $queues = config('support.monitoring.queues');

        Health::checks([
            DatabaseCheck::new(),
            CacheCheck::new(),
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(80)
                ->failWhenUsedSpaceIsAbovePercentage(95),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(3),
            QueueCheck::new()->onQueue($queues)->failWhenHealthJobTakesLongerThanMinutes(5),
            QueueBacklogCheck::new()->queues($queues),
            FailedJobsCheck::new(),
            SlaBacklogCheck::new(),
            ReverbCheck::new()->if(fn (): bool => config('broadcasting.default') === 'reverb'),
            AiProviderCheck::new()->if(fn (): bool => (bool) config('ai.enabled')),
        ]);
    }
}
