import type { ReportGranularity } from '@/types/report';

/**
 * `YYYY-MM-DD` business-timezone dates are formatted as UTC so the browser's
 * own timezone can never shift them by a day.
 */
function utcDate(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

export function formatDay(date: string): string {
    return utcDate(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    });
}

export function formatBucket(
    bucket: string,
    granularity: ReportGranularity,
): string {
    if (granularity === 'month') {
        return utcDate(bucket).toLocaleDateString(undefined, {
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        });
    }

    return granularity === 'week'
        ? `Wk of ${formatDay(bucket)}`
        : formatDay(bucket);
}

/**
 * Business minutes as hours + minutes ("3h 20m"). Not days: a business
 * "day" here is 9 hours, so "1d" would be ambiguous.
 */
export function formatMinutes(minutes: number | null): string {
    if (minutes === null) {
        return '—';
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0
        ? `${hours.toLocaleString()}h`
        : `${hours.toLocaleString()}h ${rest}m`;
}

export function formatPercent(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)}%`;
}

/** `YYYY-MM-DD` that many days before `date` (UTC maths, see utcDate). */
export function daysBefore(date: string, days: number): string {
    const result = utcDate(date);
    result.setUTCDate(result.getUTCDate() - days);

    return result.toISOString().slice(0, 10);
}
