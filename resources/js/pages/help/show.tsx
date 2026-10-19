import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { edit } from '@/routes/admin/kb-articles';
import { index, show } from '@/routes/help';
import type { KbArticle } from '@/types/kb';

type Props = { article: { data: KbArticle } };

export default function HelpShow({ article }: Props) {
    const current = article.data;

    return (
        <>
            <Head title={current.title} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            {!current.is_published && <Badge variant="destructive">Draft — only editors can see this</Badge>}
                            {current.category && <Badge variant="secondary">{current.category.name}</Badge>}
                        </div>
                        <CardTitle className="text-xl">{current.title}</CardTitle>
                        <CardDescription>Updated {new Date(current.updated_at).toLocaleDateString()}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {/* Plain text on purpose: article bodies are never rendered as HTML. */}
                        <p className="text-sm leading-relaxed whitespace-pre-wrap">{current.body}</p>
                    </CardContent>
                </Card>

                <div className="flex max-w-3xl flex-wrap justify-between gap-2">
                    <Button asChild variant="outline">
                        <Link href={index()}>All articles</Link>
                    </Button>
                    {current.can.update && (
                        <Button asChild variant="outline">
                            <Link href={edit(current.id)}>Edit article</Link>
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

HelpShow.layout = ({ article }: Props) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Help centre', href: index() },
        { title: article.data.title, href: show(article.data.slug) },
    ],
});
