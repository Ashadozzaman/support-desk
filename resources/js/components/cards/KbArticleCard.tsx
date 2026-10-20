import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { show } from '@/routes/help';
import type { KbArticleListItem } from '@/types/kb';

export function KbArticleCard({ article }: { article: KbArticleListItem }) {
    return (
        <Card className="transition-colors hover:bg-accent/50">
            <CardContent>
                <Link href={show(article.slug)} className="block space-y-2">
                    <div className="flex items-start justify-between gap-2">
                        <p className="font-medium">{article.title}</p>
                        {article.category && <Badge variant="secondary">{article.category.name}</Badge>}
                    </div>
                    <p className="text-muted-foreground line-clamp-2 text-sm">{article.excerpt}</p>
                </Link>
            </CardContent>
        </Card>
    );
}
