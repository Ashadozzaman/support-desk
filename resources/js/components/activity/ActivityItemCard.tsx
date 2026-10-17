import type { ActivityItem } from '@/types/ticket';

export function ActivityItemCard({ activity }: { activity: ActivityItem }) {
    return (
        <li className="flex gap-3 text-sm">
            <div className="bg-border mt-1.5 size-2 shrink-0 rounded-full" />
            <div className="space-y-0.5">
                <p>{activity.description}</p>
                <p className="text-muted-foreground text-xs">
                    {activity.actor?.name ?? 'System'} · {new Date(activity.created_at).toLocaleString()}
                </p>
            </div>
        </li>
    );
}
