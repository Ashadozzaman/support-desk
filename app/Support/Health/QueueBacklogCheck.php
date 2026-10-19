<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Age of the oldest job still waiting to be picked up, per queue (database
 * driver: the `jobs` table). QueueCheck proves a worker answers; this proves
 * it keeps up. `imports` is left out on purpose: one hour-long import makes
 * the next one wait legitimately.
 */
class QueueBacklogCheck extends Check
{
    /** @var list<string> */
    protected array $queues = [];

    protected int $warnAfterMinutes = 5;

    protected int $failAfterMinutes = 15;

    /**
     * @param  list<string>  $queues
     */
    public function queues(array $queues): self
    {
        $this->queues = $queues;

        return $this;
    }

    public function warnAfterMinutes(int $minutes): self
    {
        $this->warnAfterMinutes = $minutes;

        return $this;
    }

    public function failAfterMinutes(int $minutes): self
    {
        $this->failAfterMinutes = $minutes;

        return $this;
    }

    public function run(): Result
    {
        // available_at, not created_at: a job released with a backoff isn't late.
        $oldest = DB::table('jobs')
            ->whereIn('queue', $this->queues)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->selectRaw('queue, MIN(available_at) AS oldest')
            ->groupBy('queue')
            ->pluck('oldest', 'queue');

        $ageMinutes = $oldest->map(fn ($ts) => intdiv(now()->getTimestamp() - (int) $ts, 60));
        $worst = (int) ($ageMinutes->max() ?? 0);
        $worstQueue = $ageMinutes->search($worst);

        $result = Result::make()->meta(['oldest_minutes_by_queue' => $ageMinutes->all()])->shortSummary("{$worst} min");

        return match (true) {
            $worst >= $this->failAfterMinutes => $result->failed("Oldest waiting job on \"{$worstQueue}\" is {$worst} minutes old."),
            $worst >= $this->warnAfterMinutes => $result->warning("Oldest waiting job on \"{$worstQueue}\" is {$worst} minutes old."),
            default => $result->ok(),
        };
    }
}
