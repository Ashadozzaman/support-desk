import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/types/pagination';

/**
 * Laravel's paginator labels carry HTML entities ("&laquo; Previous").
 * These are fixed framework strings, not user content, but we still avoid
 * dangerouslySetInnerHTML entirely by mapping them to plain text.
 */
function labelText(label: string): string {
    if (label.includes('Previous')) {
        return 'Previous';
    }
    if (label.includes('Next')) {
        return 'Next';
    }
    return label;
}

export function Pagination({ meta }: { meta: PaginationMeta }) {
    if (meta.last_page <= 1) {
        return null;
    }

    return (
        <nav className="flex items-center justify-between gap-4">
            <p className="text-muted-foreground text-sm">
                Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
            </p>
            <div className="flex items-center gap-1">
                {meta.links.map((link, index) =>
                    link.url !== null ? (
                        <Button
                            key={`${link.label}-${index}`}
                            asChild
                            variant={link.active ? 'default' : 'outline'}
                            size="sm"
                        >
                            <Link href={link.url} preserveScroll preserveState>
                                {labelText(link.label)}
                            </Link>
                        </Button>
                    ) : (
                        <Button key={`${link.label}-${index}`} variant="outline" size="sm" disabled>
                            {labelText(link.label)}
                        </Button>
                    ),
                )}
            </div>
        </nav>
    );
}
