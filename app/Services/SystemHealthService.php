<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\HealthReport;
use Carbon\CarbonImmutable;
use Exception;
use Spatie\Health\Checks\Check;
use Spatie\Health\Enums\Status;
use Spatie\Health\Health;

/**
 * Runs the checks registered in AppServiceProvider::configureHealthChecks()
 * on demand. Done here rather than through spatie's `health:check` command +
 * result store: both callers (GET /health, the System health page) want a
 * fresh answer, so there is nothing worth persisting.
 */
class SystemHealthService
{
    private const FAILING = ['failed', 'crashed'];

    public function __construct(private readonly Health $health) {}

    public function run(): HealthReport
    {
        $checks = array_values($this->health->registeredChecks()
            ->map(fn (Check $check) => $this->runCheck($check))
            ->all());

        $healthy = collect($checks)->every(fn (array $c) => ! in_array($c['status'], self::FAILING, true));

        return new HealthReport($checks, $healthy, CarbonImmutable::now());
    }

    /**
     * @return array{name: string, label: string, status: string, summary: string, message: string}
     */
    private function runCheck(Check $check): array
    {
        $row = ['name' => $check->getName(), 'label' => $check->getLabel()];

        if (! $check->shouldRun()) {
            return [...$row, 'status' => $this->statusName(Status::skipped()), 'summary' => 'Not in use', 'message' => ''];
        }

        try {
            $result = $check->run();
        } catch (Exception $e) {
            // A check that throws (e.g. DB unreachable) is itself the signal.
            report($e);

            return [...$row, 'status' => $this->statusName(Status::crashed()), 'summary' => 'Crashed', 'message' => $e::class];
        }

        return [
            ...$row,
            'status' => $this->statusName($result->status),
            'summary' => $result->getShortSummary(),
            'message' => $result->getNotificationMessage(),
        ];
    }

    /**
     * spatie/enum types `value` as int|string; Status values are always the
     * strings ok/warning/failed/crashed/skipped.
     */
    private function statusName(Status $status): string
    {
        return (string) $status->value;
    }
}
