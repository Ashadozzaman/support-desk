import { Head, Link, router } from '@inertiajs/react';
import { ConfirmDialog } from '@/components/feedback/ConfirmDialog';
import { KbArticleForm } from '@/components/kb/KbArticleForm';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { destroy, edit, index } from '@/routes/admin/kb-articles';
import { show } from '@/routes/help';
import type { KbArticle } from '@/types/kb';
import type { CategoryOption } from '@/types/ticket';

type Props = {
    article: { data: KbArticle };
    categories: CategoryOption[];
};

export default function KbArticlesEdit({ article, categories }: Props) {
    const current = article.data;

    return (
        <>
            <Head title={`Edit ${current.title}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <KbArticleForm mode="edit" article={current} categories={categories} />

                <div className="flex flex-wrap justify-end gap-2">
                    <Button asChild variant="outline">
                        <Link href={show(current.slug)}>{current.is_published ? 'View in help centre' : 'Preview'}</Link>
                    </Button>
                    <ConfirmDialog
                        trigger={<Button variant="destructive">Delete article</Button>}
                        title={`Delete “${current.title}”?`}
                        description="This can't be undone. Links to it will stop working."
                        confirmLabel="Delete"
                        destructive
                        onConfirm={() => router.delete(destroy(current.id).url)}
                    />
                </div>
            </div>
        </>
    );
}

KbArticlesEdit.layout = ({ article }: Props) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Knowledge base', href: index() },
        { title: article.data.title, href: edit(article.data.id) },
    ],
});
