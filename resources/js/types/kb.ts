import type { CategoryOption } from '@/types/ticket';

/** List row: an excerpt of the body, never the whole of it. */
export type KbArticleListItem = {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    is_published: boolean;
    category: CategoryOption | null;
    updated_at: string;
};

export type KbArticle = {
    id: number;
    title: string;
    slug: string;
    /** Plain text: render with whitespace-pre-wrap, never as HTML. */
    body: string;
    is_published: boolean;
    category_id: number | null;
    category: CategoryOption | null;
    updated_at: string;
    can: { update: boolean };
};
