import { Head, Link } from '@inertiajs/react';
import { BookOpenIcon, PlusIcon } from 'lucide-react';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { KbArticleTable } from '@/components/kb/KbArticleTable';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/admin/kb-articles';
import type { KbArticleListItem } from '@/types/kb';
import type { Paginated } from '@/types/pagination';

export default function KbArticlesIndex({ articles }: { articles: Paginated<KbArticleListItem> }) {
    return (
        <>
            <Head title="Knowledge base" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Knowledge base</h1>
                        <p className="text-muted-foreground text-sm">
                            Help-centre articles. Only published ones are visible to customers and agents.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={create()}>
                            <PlusIcon />
                            New article
                        </Link>
                    </Button>
                </div>

                {articles.data.length === 0 ? (
                    <EmptyState
                        icon={BookOpenIcon}
                        title="No articles yet"
                        description="Write the first help-centre article."
                    />
                ) : (
                    <>
                        <KbArticleTable articles={articles.data} />
                        <Pagination meta={articles.meta} />
                    </>
                )}
            </div>
        </>
    );
}

KbArticlesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Knowledge base', href: index() },
    ],
};
