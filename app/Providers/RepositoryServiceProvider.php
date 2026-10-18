<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Assignment\AssignmentStrategy;
use App\Contracts\Repositories\ActivityRepositoryInterface;
use App\Contracts\Repositories\AgentRepositoryInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Contracts\Repositories\ReplyRepositoryInterface;
use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Repositories\Eloquent\ActivityRepository;
use App\Repositories\Eloquent\AgentRepository;
use App\Repositories\Eloquent\AttachmentRepository;
use App\Repositories\Eloquent\DashboardRepository;
use App\Repositories\Eloquent\ImportRepository;
use App\Repositories\Eloquent\ReplyRepository;
use App\Repositories\Eloquent\SlaConfigRepository;
use App\Repositories\Eloquent\TicketRepository;
use App\Support\Assignment\LeastWorkloadStrategy;
use App\Support\Assignment\RoundRobinStrategy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Repository interface => Eloquent implementation bindings are
        // added here as each repository is introduced (see CODING_STRUCTURE.md §5).
        $this->app->bind(TicketRepositoryInterface::class, TicketRepository::class);
        $this->app->bind(ActivityRepositoryInterface::class, ActivityRepository::class);
        $this->app->bind(ReplyRepositoryInterface::class, ReplyRepository::class);
        $this->app->bind(AttachmentRepositoryInterface::class, AttachmentRepository::class);
        $this->app->bind(AgentRepositoryInterface::class, AgentRepository::class);
        $this->app->bind(SlaConfigRepositoryInterface::class, SlaConfigRepository::class);
        $this->app->bind(DashboardRepositoryInterface::class, DashboardRepository::class);
        $this->app->bind(ImportRepositoryInterface::class, ImportRepository::class);

        // Strategy selected from config, not hard-coded (ARCHITECTURE.md §6.2 OCP note).
        $this->app->bind(AssignmentStrategy::class, function (Application $app) {
            return match (config('support.assignment.strategy')) {
                'round_robin' => $app->make(RoundRobinStrategy::class),
                default => $app->make(LeastWorkloadStrategy::class),
            };
        });
    }
}
