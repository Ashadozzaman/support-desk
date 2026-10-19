<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Ai\AiClient;
use App\Contracts\Assignment\AssignmentStrategy;
use App\Contracts\Repositories\ActivityRepositoryInterface;
use App\Contracts\Repositories\AgentRepositoryInterface;
use App\Contracts\Repositories\AiInteractionRepositoryInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\AuditLogRepositoryInterface;
use App\Contracts\Repositories\ChatRepositoryInterface;
use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Contracts\Repositories\InboundMessageRepositoryInterface;
use App\Contracts\Repositories\KnowledgeBaseRepositoryInterface;
use App\Contracts\Repositories\ReplyRepositoryInterface;
use App\Contracts\Repositories\ReportExportRepositoryInterface;
use App\Contracts\Repositories\ReportRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\Eloquent\ActivityRepository;
use App\Repositories\Eloquent\AgentRepository;
use App\Repositories\Eloquent\AiInteractionRepository;
use App\Repositories\Eloquent\AttachmentRepository;
use App\Repositories\Eloquent\AuditLogRepository;
use App\Repositories\Eloquent\ChatRepository;
use App\Repositories\Eloquent\DashboardRepository;
use App\Repositories\Eloquent\ImportRepository;
use App\Repositories\Eloquent\InboundMessageRepository;
use App\Repositories\Eloquent\KnowledgeBaseRepository;
use App\Repositories\Eloquent\ReplyRepository;
use App\Repositories\Eloquent\ReportExportRepository;
use App\Repositories\Eloquent\ReportRepository;
use App\Repositories\Eloquent\RoleRepository;
use App\Repositories\Eloquent\SlaConfigRepository;
use App\Repositories\Eloquent\TicketRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Support\Ai\AnthropicClient;
use App\Support\Ai\FakeAiClient;
use App\Support\Ai\OpenAiClient;
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
        $this->app->bind(ReportRepositoryInterface::class, ReportRepository::class);
        $this->app->bind(ReportExportRepositoryInterface::class, ReportExportRepository::class);
        $this->app->bind(AuditLogRepositoryInterface::class, AuditLogRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(KnowledgeBaseRepositoryInterface::class, KnowledgeBaseRepository::class);
        $this->app->bind(AiInteractionRepositoryInterface::class, AiInteractionRepository::class);
        $this->app->bind(ChatRepositoryInterface::class, ChatRepository::class);
        $this->app->bind(InboundMessageRepositoryInterface::class, InboundMessageRepository::class);

        // Strategy selected from config, not hard-coded (ARCHITECTURE.md §6.2 OCP note).
        $this->app->bind(AssignmentStrategy::class, function (Application $app) {
            return match (config('support.assignment.strategy')) {
                'round_robin' => $app->make(RoundRobinStrategy::class),
                default => $app->make(LeastWorkloadStrategy::class),
            };
        });

        // Real provider only when AI is on *and* configured for it; otherwise
        // the deterministic fake, so dev/CI/tests never need a key (ENHANCEMENTS.md §3.1).
        $this->app->bind(AiClient::class, function (Application $app) {
            if (! config('ai.enabled')) {
                return $app->make(FakeAiClient::class);
            }

            return match (config('ai.provider')) {
                'anthropic' => $app->make(AnthropicClient::class),
                'openai' => $app->make(OpenAiClient::class),
                default => $app->make(FakeAiClient::class),
            };
        });
    }
}
