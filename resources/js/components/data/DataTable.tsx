import type { ReactNode } from 'react';

export type DataColumn<T> = {
    header: string;
    cell: (row: T) => ReactNode;
    /** Numbers read best right-aligned. */
    numeric?: boolean;
};

/**
 * Generic report table: a real table from `md` up, stacked "label: value"
 * cards below it (same responsive rule as TicketTable). The first column is
 * the row's title on mobile.
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
}: {
    columns: DataColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
}) {
    const [titleColumn, ...detailColumns] = columns;

    return (
        <>
            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.header}
                                    className={`px-4 py-2 font-medium ${column.numeric ? 'text-right' : ''}`}
                                >
                                    {column.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={rowKey(row)} className="border-t">
                                {columns.map((column) => (
                                    <td
                                        key={column.header}
                                        className={`px-4 py-2 ${column.numeric ? 'text-right tabular-nums' : 'font-medium'}`}
                                    >
                                        {column.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-3 md:hidden">
                {rows.map((row) => (
                    <div
                        key={rowKey(row)}
                        className="flex flex-col gap-2 rounded-lg border p-4"
                    >
                        <div className="font-medium">
                            {titleColumn.cell(row)}
                        </div>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            {detailColumns.map((column) => (
                                <div key={column.header} className="contents">
                                    <dt className="text-muted-foreground">
                                        {column.header}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {column.cell(row)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                ))}
            </div>
        </>
    );
}
