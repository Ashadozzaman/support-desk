import { Head } from '@inertiajs/react';
import { ChatWidget } from '@/components/chat/ChatWidget';
import { dashboard } from '@/routes';
import { index } from '@/routes/help';
import { show } from '@/routes/help/chat';
import type { ChatMessage, ChatSources } from '@/types/chat';

type Props = {
    messages: { data: ChatMessage[] };
    sources: ChatSources;
    maxLength: number;
};

export default function HelpChat({ messages, sources, maxLength }: Props) {
    return (
        <>
            <Head title="AI help assistant" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <ChatWidget messages={messages.data} sources={sources} maxLength={maxLength} />
            </div>
        </>
    );
}

HelpChat.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Help centre', href: index() },
        { title: 'AI assistant', href: show() },
    ],
};
