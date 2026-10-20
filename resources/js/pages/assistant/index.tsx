import { Head } from '@inertiajs/react';
import { ChatWidget } from '@/components/chat/ChatWidget';
import { dashboard } from '@/routes';
import { ask, restart, show } from '@/routes/assistant';
import type { ChatMessage } from '@/types/chat';

type Props = {
    messages: { data: ChatMessage[] };
    maxLength: number;
};

export default function DataAssistant({ messages, maxLength }: Props) {
    return (
        <>
            <Head title="Data assistant" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <ChatWidget
                    title="Data assistant"
                    description="Ask about tickets and reports in plain English. The AI looks the answer up with read-only searches and only sees what you can see on the Tickets and Reports pages. Check important numbers there."
                    emptyHint="For example: “latest tickets”, “what was resolved today?”, “last 7 days report”, “open critical tickets”."
                    askUrl={ask().url}
                    restartUrl={restart().url}
                    messages={messages.data}
                    maxLength={maxLength}
                />
            </div>
        </>
    );
}

DataAssistant.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Data assistant', href: show() },
    ],
};
