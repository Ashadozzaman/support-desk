import { Link } from '@inertiajs/react';
import { BotIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { show } from '@/routes/help';
import type { ChatMessage, ChatSources } from '@/types/chat';

export function ChatMessageItem({ message, sources }: { message: ChatMessage; sources: ChatSources }) {
    const fromAssistant = message.role === 'assistant';
    const cited = message.kb_article_ids.map((id, index) => ({ number: index + 1, article: sources[String(id)] }));

    return (
        <li className={cn('flex', fromAssistant ? 'justify-start' : 'justify-end')}>
            <div
                className={cn(
                    'max-w-[85%] space-y-2 rounded-lg px-3 py-2 text-sm',
                    fromAssistant ? 'bg-muted' : 'bg-primary text-primary-foreground',
                )}
            >
                {fromAssistant && (
                    <Badge variant="outline" className="gap-1">
                        <BotIcon className="size-3" />
                        AI assistant
                    </Badge>
                )}
                <span className="sr-only">{fromAssistant ? 'AI assistant said:' : 'You said:'}</span>
                <p className="whitespace-pre-wrap">{message.content}</p>
                {cited.some((source) => source.article) && (
                    <div className="space-y-1 border-t pt-2">
                        <p className="text-muted-foreground text-xs font-medium">Sources</p>
                        <ul className="space-y-0.5 text-xs">
                            {cited.map(
                                ({ number, article }) =>
                                    article && (
                                        <li key={number}>
                                            [{number}]{' '}
                                            <Link href={show(article.slug)} className="underline underline-offset-2">
                                                {article.title}
                                            </Link>
                                        </li>
                                    ),
                            )}
                        </ul>
                    </div>
                )}
            </div>
        </li>
    );
}
