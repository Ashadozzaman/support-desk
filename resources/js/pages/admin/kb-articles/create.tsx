import { Head } from '@inertiajs/react';
import { KbArticleForm } from '@/components/kb/KbArticleForm';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/admin/kb-articles';
import type { CategoryOption } from '@/types/ticket';

export default function KbArticlesCreate({ categories }: { categories: CategoryOption[] }) {
    return (
        <>
            <Head title="New article" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <KbArticleForm mode="create" categories={categories} />
            </div>
        </>
    );
}

KbArticlesCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Knowledge base', href: index() },
        { title: 'New', href: create() },
    ],
};
