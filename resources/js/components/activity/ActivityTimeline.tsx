import { ActivityItemCard } from '@/components/activity/ActivityItemCard';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ActivityItem } from '@/types/ticket';

export function ActivityTimeline({ activities }: { activities: ActivityItem[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Activity</CardTitle>
            </CardHeader>
            <CardContent>
                {activities.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No activity yet.</p>
                ) : (
                    <ul className="space-y-4">
                        {activities.map((activity) => (
                            <ActivityItemCard key={activity.id} activity={activity} />
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
