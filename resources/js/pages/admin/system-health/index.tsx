import { Head, router } from '@inertiajs/react';
import { ActivityIcon, CheckCircle2Icon, RefreshCwIcon, XCircleIcon } from 'lucide-react';
import { HealthCheckCard } from '@/components/cards/HealthCheckCard';
import { StatCard } from '@/components/cards/StatCard';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { systemHealth } from '@/routes/admin';
import type { HealthReport } from '@/types/monitoring';

export default function SystemHealthIndex({ report }: { report: HealthReport }) {
    const failing = report.checks.filter((c) => c.status === 'failed' || c.status === 'crashed').length;
    const warnings = report.checks.filter((c) => c.status === 'warning').length;

    return (
        <>
            <Head title="System health" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">System health</h1>
                        <p className="text-muted-foreground text-sm">
                            Checked {new Date(report.checked_at).toLocaleString()}. Same checks as GET /health.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {/* Pulse is a Livewire page, not an Inertia one — plain link, full page load. */}
                        <Button variant="outline" asChild>
                            <a href="/pulse">
                                <ActivityIcon /> Open Pulse
                            </a>
                        </Button>
                        <Button onClick={() => router.reload({ only: ['report'] })}>
                            <RefreshCwIcon /> Re-run checks
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        label="Overall"
                        value={report.healthy ? 'Healthy' : 'Unhealthy'}
                        icon={report.healthy ? CheckCircle2Icon : XCircleIcon}
                    />
                    <StatCard label="Failing checks" value={failing} />
                    <StatCard label="Warnings" value={warnings} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {report.checks.map((check) => (
                        <HealthCheckCard key={check.name} check={check} />
                    ))}
                </div>
            </div>
        </>
    );
}

SystemHealthIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'System health', href: systemHealth() },
    ],
};
