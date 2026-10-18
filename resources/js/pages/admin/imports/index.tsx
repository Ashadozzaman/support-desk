import { Head, Link } from '@inertiajs/react';
import { DownloadIcon, PlusIcon, UploadIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { ImportTable } from '@/components/import/ImportTable';
import { dashboard } from '@/routes';
import { create, index, sample } from '@/routes/admin/imports';
import type { Paginated } from '@/types/pagination';
import type { ImportSummary } from '@/types/import';

export default function ImportsIndex({ imports }: { imports: Paginated<ImportSummary> }) {
    return (
        <>
            <Head title="CSV Imports" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">CSV Imports</h1>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline">
                            <a href={sample.url()}>
                                <DownloadIcon />
                                Sample CSV
                            </a>
                        </Button>
                        <Button asChild>
                            <Link href={create().url}>
                                <PlusIcon />
                                New import
                            </Link>
                        </Button>
                    </div>
                </div>

                {imports.data.length === 0 ? (
                    <EmptyState
                        icon={UploadIcon}
                        title="No imports yet"
                        description="Upload a CSV file to bulk-create tickets."
                        action={
                            <Button asChild variant="outline">
                                <Link href={create().url}>New import</Link>
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <ImportTable imports={imports.data} />
                        <Pagination meta={imports.meta} />
                    </>
                )}
            </div>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'CSV Imports', href: index() },
    ],
};
