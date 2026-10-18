import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ImportProgressBar } from '@/components/import/ImportProgressBar';
import { ImportStatusBadge } from '@/components/import/ImportStatusBadge';
import { useImportChannel } from '@/hooks/use-import-channel';
import { usePolling } from '@/hooks/use-polling';
import { dashboard } from '@/routes';
import { errors as errorsRoute, index, show } from '@/routes/admin/imports';
import type { ImportSummary } from '@/types/import';

export default function ImportsShow({ import: importResource }: { import: { data: ImportSummary } }) {
    const item = importResource.data;

    useImportChannel(item.id);
    usePolling(['import']);

    const isDone = item.status === 'completed' || item.status === 'completed_with_errors' || item.status === 'failed';

    return (
        <>
            <Head title={`Import: ${item.original_filename}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>{item.original_filename}</CardTitle>
                        <ImportStatusBadge status={item.status} />
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <ImportProgressBar processed={item.processed_rows} total={item.total_rows} />

                        <div className="flex gap-4 text-sm">
                            <Badge variant="outline">{item.success_rows} succeeded</Badge>
                            <Badge variant="outline">{item.failed_rows} failed</Badge>
                        </div>

                        {item.failed_rows > 0 && (
                            <a
                                href={errorsRoute(item.id).url}
                                className="text-primary text-sm hover:underline"
                            >
                                Download error report (CSV)
                            </a>
                        )}

                        {!isDone && (
                            <p className="text-muted-foreground text-xs">
                                Processing in the background — this page updates automatically.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {item.errors && item.errors.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                First {item.errors.length} errors
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="divide-y text-sm">
                                {item.errors.map((error) => (
                                    <div key={error.row_number} className="py-2">
                                        <p className="font-medium">Row {error.row_number}</p>
                                        <p className="text-muted-foreground">{error.errors.join('; ')}</p>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

ImportsShow.layout = (props: { import: { data: ImportSummary } }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'CSV Imports', href: index() },
        { title: props.import.data.original_filename, href: show(props.import.data.id) },
    ],
});
