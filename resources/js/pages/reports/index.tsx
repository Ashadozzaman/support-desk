import { Head, usePage } from '@inertiajs/react';
import { ReportFilterCard } from '@/components/cards/ReportFilterCard';
import { AgentsReportView } from '@/components/report/AgentsReportView';
import { AgingReportView } from '@/components/report/AgingReportView';
import { ExportButton } from '@/components/report/ExportButton';
import { CategoriesReportView } from '@/components/report/CategoriesReportView';
import { OverviewReportView } from '@/components/report/OverviewReportView';
import { ReportTabs } from '@/components/report/ReportTabs';
import { ResponseResolutionReportView } from '@/components/report/ResponseResolutionReportView';
import { SlaReportView } from '@/components/report/SlaReportView';
import { dashboard } from '@/routes';
import { index } from '@/routes/reports';
import type {
    ReportData,
    ReportFilters,
    ReportOptions,
    ReportTab,
} from '@/types/report';

/** `tab` decides the shape of `report` — narrowing on it types each view. */
type TabProps = {
    [T in ReportTab]: { tab: T; report: ReportData[T] };
}[ReportTab];

type Props = TabProps & {
    filters: ReportFilters;
    options: ReportOptions;
    today: string;
    percentileMaxDays: number;
};

function ReportBody(props: Props) {
    switch (props.tab) {
        case 'overview':
            return (
                <OverviewReportView
                    report={props.report}
                    granularity={props.filters.granularity}
                />
            );
        case 'sla':
            return (
                <SlaReportView
                    report={props.report}
                    granularity={props.filters.granularity}
                />
            );
        case 'response':
            return (
                <ResponseResolutionReportView
                    report={props.report}
                    percentileMaxDays={props.percentileMaxDays}
                />
            );
        case 'agents':
            return <AgentsReportView rows={props.report} />;
        case 'aging':
            return <AgingReportView report={props.report} />;
        case 'categories':
            return <CategoriesReportView rows={props.report} />;
    }
}

export default function ReportsIndex(props: Props) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Reports" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-lg font-semibold">Reports</h1>
                        <p className="text-sm text-muted-foreground">
                            Counts and averages are refreshed every 15 minutes.
                            Days are in the business timezone.
                        </p>
                    </div>
                    <ExportButton tab={props.tab} filters={props.filters} />
                </div>

                <ReportTabs active={props.tab} filters={props.filters} />

                <ReportFilterCard
                    tab={props.tab}
                    filters={props.filters}
                    options={props.options}
                    today={props.today}
                    errors={errors}
                />

                <ReportBody {...props} />
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Reports', href: index() },
    ],
};
