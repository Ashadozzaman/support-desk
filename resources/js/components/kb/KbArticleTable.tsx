import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/data/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/admin/kb-articles';
import type { KbArticleListItem } from '@/types/kb';

export function KbArticleTable({ articles }: { articles: KbArticleListItem[] }) {
    return (
        <DataTable
            rows={articles}
            rowKey={(article) => article.id}
            columns={[
                {
                    header: 'Article',
                    cell: (article) => (
                        <div className="space-y-0.5">
                            <p className="font-medium">{article.title}</p>
                            <p className="text-muted-foreground font-mono text-xs">/help/{article.slug}</p>
                        </div>
                    ),
                },
                { header: 'Category', cell: (article) => article.category?.name ?? '—' },
                {
                    header: 'Status',
                    cell: (article) =>
                        article.is_published ? <Badge>Published</Badge> : <Badge variant="secondary">Draft</Badge>,
                },
                { header: 'Updated', cell: (article) => new Date(article.updated_at).toLocaleDateString() },
                {
                    header: '',
                    cell: (article) => (
                        <Button asChild variant="outline" size="sm">
                            <Link href={edit(article.id)}>Edit</Link>
                        </Button>
                    ),
                },
            ]}
        />
    );
}
