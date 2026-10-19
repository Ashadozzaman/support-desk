import { HealthStatusBadge } from '@/components/monitoring/HealthStatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { HealthCheckRow } from '@/types/monitoring';

export function HealthCheckCard({ check }: { check: HealthCheckRow }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="text-base">{check.label}</CardTitle>
                <HealthStatusBadge status={check.status} />
            </CardHeader>
            <CardContent className="space-y-1">
                <p className="text-2xl font-semibold">{check.summary}</p>
                {check.message !== '' && <p className="text-muted-foreground text-sm">{check.message}</p>}
            </CardContent>
        </Card>
    );
}
