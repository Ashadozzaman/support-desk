export function ImportProgressBar({ processed, total }: { processed: number; total: number }) {
    const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

    return (
        <div className="space-y-1">
            <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                <div
                    className="bg-primary h-full rounded-full transition-all"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <p className="text-muted-foreground text-xs">
                {processed} / {total} rows processed ({percent}%)
            </p>
        </div>
    );
}
