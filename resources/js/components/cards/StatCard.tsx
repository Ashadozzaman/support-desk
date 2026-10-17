import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

/**
 * Built now, consumed by the dashboard in Step 13 (ARCHITECTURE.md §14).
 */
export function StatCard({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string | number;
    icon?: LucideIcon;
}) {
    return (
        <Card>
            <CardContent className="flex items-center justify-between gap-4">
                <div>
                    <p className="text-muted-foreground text-sm">{label}</p>
                    <p className="text-2xl font-semibold">{value}</p>
                </div>
                {Icon && <Icon className="text-muted-foreground size-8" />}
            </CardContent>
        </Card>
    );
}
