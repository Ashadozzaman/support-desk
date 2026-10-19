/**
 * `today` comes from the server (business timezone), so presets never
 * drift with the browser clock. Plain UTC date maths on the `YYYY-MM-DD`
 * string avoids local-timezone off-by-one days.
 */
export function daysBefore(today: string, days: number): string {
    const date = new Date(`${today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() - days);

    return date.toISOString().slice(0, 10);
}
