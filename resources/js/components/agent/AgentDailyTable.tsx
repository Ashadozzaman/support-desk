import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { AgentDailyStats } from '@/types/agent';

/** `YYYY-MM-DD` → "Sat, 18 Oct" without letting the browser's timezone shift the day. */
function dayLabel(date: string): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
}

/**
 * One row per day of the selected month (business timezone, ARCHITECTURE.md
 * §14.1): tickets assigned to the agent that day and tickets they resolved
 * or closed that day. The same rows back the CSV export.
 */
export function AgentDailyTable({ daily }: { daily: AgentDailyStats }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Day by day — {daily.month}</CardTitle>
            </CardHeader>
            <CardContent>
                {daily.days.length === 0 ? (
                    <p className="text-muted-foreground text-sm">This month hasn't started yet.</p>
                ) : (
                    <div className="max-h-[32rem] overflow-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted text-muted-foreground sticky top-0 text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Date</th>
                                    <th className="px-4 py-2 text-right font-medium">Assigned</th>
                                    <th className="px-4 py-2 text-right font-medium">Completed</th>
                                </tr>
                            </thead>
                            <tbody>
                                {daily.days.map((day) => (
                                    <tr key={day.date} className="border-t">
                                        <td className="px-4 py-2">{dayLabel(day.date)}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{day.assigned}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{day.completed}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="bg-muted sticky bottom-0 font-medium">
                                <tr className="border-t">
                                    <td className="px-4 py-2">Total</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{daily.totals.assigned}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{daily.totals.completed}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
