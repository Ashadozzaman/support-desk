/** Mirrors App\DTOs\HealthReport::toArray(); statuses are spatie/laravel-health's. */
export type HealthStatusValue = 'ok' | 'warning' | 'failed' | 'crashed' | 'skipped';

export type HealthCheckRow = {
    name: string;
    label: string;
    status: HealthStatusValue;
    summary: string;
    message: string;
};

export type HealthReport = {
    healthy: boolean;
    checked_at: string;
    checks: HealthCheckRow[];
};
