import { Link } from '@inertiajs/react';
import { ImportStatusBadge } from '@/components/import/ImportStatusBadge';
import { show } from '@/routes/admin/imports';
import type { ImportSummary } from '@/types/import';

export function ImportTable({ imports }: { imports: ImportSummary[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th className="px-4 py-2 font-medium">File</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium">Rows</th>
                        <th className="px-4 py-2 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody>
                    {imports.map((item) => (
                        <tr key={item.id} className="border-t">
                            <td className="px-4 py-2">
                                <Link href={show(item.id).url} className="hover:underline">
                                    {item.original_filename}
                                </Link>
                            </td>
                            <td className="px-4 py-2">
                                <ImportStatusBadge status={item.status} />
                            </td>
                            <td className="px-4 py-2">
                                {item.success_rows} ok / {item.failed_rows} failed / {item.total_rows} total
                            </td>
                            <td className="text-muted-foreground px-4 py-2">
                                {new Date(item.created_at).toLocaleString()}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
