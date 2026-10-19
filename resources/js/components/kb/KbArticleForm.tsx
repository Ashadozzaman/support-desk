import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { CategorySelect } from '@/components/form/CategorySelect';
import { CheckboxInput } from '@/components/form/CheckboxInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextAreaInput } from '@/components/form/TextAreaInput';
import { TextInput } from '@/components/form/TextInput';
import { store, update } from '@/routes/admin/kb-articles';
import type { KbArticle } from '@/types/kb';
import type { CategoryOption } from '@/types/ticket';

type KbArticleFormValues = {
    title: string;
    slug: string;
    body: string;
    is_published: boolean;
    category_id: number | null;
};

function slugify(title: string): string {
    return title
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 120);
}

/**
 * Shared create/edit form. While creating, the slug follows the title until
 * the admin edits it by hand; on edit it stays put, because changing it
 * changes the article's URL.
 */
export function KbArticleForm({
    mode,
    article,
    categories,
}: {
    mode: 'create' | 'edit';
    article?: KbArticle;
    categories: CategoryOption[];
}) {
    const form = useForm<KbArticleFormValues>({
        title: article?.title ?? '',
        slug: article?.slug ?? '',
        body: article?.body ?? '',
        is_published: article?.is_published ?? false,
        category_id: article?.category_id ?? null,
    });

    const [slugTouched, setSlugTouched] = useState(mode === 'edit');

    function submit(event: FormEvent) {
        event.preventDefault();

        if (mode === 'create') {
            form.post(store().url);
        } else if (article) {
            form.put(update(article.id).url);
        }
    }

    return (
        <FormCard
            title={mode === 'create' ? 'New article' : 'Edit article'}
            description="Plain text. Published articles appear in the help centre for every signed-in user."
            onSubmit={submit}
            footer={
                <SubmitButton processing={form.processing}>
                    {mode === 'create' ? 'Create article' : 'Save changes'}
                </SubmitButton>
            }
        >
            <TextInput
                name="title"
                label="Title"
                value={form.data.title}
                onChange={(event) => {
                    const title = event.target.value;
                    form.setData((data) => ({ ...data, title, slug: slugTouched ? data.slug : slugify(title) }));
                }}
                error={form.errors.title}
                maxLength={200}
                required
            />

            <TextInput
                name="slug"
                label="URL slug"
                value={form.data.slug}
                onChange={(event) => {
                    setSlugTouched(true);
                    form.setData('slug', event.target.value.toLowerCase());
                }}
                error={form.errors.slug}
                placeholder="e.g. reset-your-password"
                maxLength={120}
                required
            />

            <CategorySelect
                value={form.data.category_id}
                categories={categories}
                onChange={(value) => form.setData('category_id', value)}
                error={form.errors.category_id}
            />

            <TextAreaInput
                name="body"
                label="Article"
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                error={form.errors.body}
                rows={14}
                maxLength={20000}
                required
            />

            <CheckboxInput
                name="is_published"
                label="Published"
                checked={form.data.is_published}
                onChange={(checked) => form.setData('is_published', checked)}
                error={form.errors.is_published}
            />
        </FormCard>
    );
}
