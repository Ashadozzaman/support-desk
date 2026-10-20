import { Head, router } from '@inertiajs/react';
import { BookOpenIcon } from 'lucide-react';
import { AskAssistantCard } from '@/components/cards/AskAssistantCard';
import { FilterCard } from '@/components/cards/FilterCard';
import { KbArticleCard } from '@/components/cards/KbArticleCard';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { SearchInput } from '@/components/form/SearchInput';
import { dashboard } from '@/routes';
import { index } from '@/routes/help';
import type { KbArticleListItem } from '@/types/kb';
import type { Paginated } from '@/types/pagination';

export default function HelpIndex({
    articles,
    query,
    chatAvailable,
}: {
    articles: Paginated<KbArticleListItem>;
    query: string;
    chatAvailable: boolean;
}) {
    function search(term: string) {
        if (term === query) {
            return;
        }

        router.get(index({ query: term ? { q: term } : {} }).url, {}, { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Help centre" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Help centre</h1>
                    <p className="text-muted-foreground text-sm">
                        Answers to common questions.
                    </p>
                </div>

                <AskAssistantCard chatAvailable={chatAvailable} />

                <FilterCard>
                    <SearchInput
                        value={query}
                        onSearch={search}
                        placeholder="Search articles…"
                        className="w-full sm:w-80"
                    />
                </FilterCard>

                {articles.data.length === 0 ? (
                    <EmptyState
                        icon={BookOpenIcon}
                        title={query ? 'No matching articles' : 'No articles yet'}
                        description={query ? 'Try different words (at least 3 letters).' : undefined}
                    />
                ) : (
                    <>
                        <div className="grid gap-3 md:grid-cols-2">
                            {articles.data.map((article) => (
                                <KbArticleCard key={article.id} article={article} />
                            ))}
                        </div>
                        <Pagination meta={articles.meta} />
                    </>
                )}
            </div>
        </>
    );
}

HelpIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Help centre', href: index() },
    ],
};
