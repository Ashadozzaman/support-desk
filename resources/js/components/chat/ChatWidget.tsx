import { router, useForm, usePage } from '@inertiajs/react';
import { BotIcon, RotateCcwIcon, UserRoundIcon } from 'lucide-react';
import type { FormEvent, KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { ChatMessageItem } from '@/components/chat/ChatMessageItem';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextAreaInput } from '@/components/form/TextAreaInput';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { ask, handoff, restart } from '@/routes/help/chat';
import type { ChatMessage, ChatSources } from '@/types/chat';

/**
 * The customer's conversation with the AI assistant. Each question is a
 * normal Inertia POST: the server stores it, answers, and the page reloads
 * the messages. "Talk to a human" is always available, and highlighted when
 * the assistant couldn't answer.
 */
export function ChatWidget({
    messages,
    sources,
    maxLength,
}: {
    messages: ChatMessage[];
    sources: ChatSources;
    maxLength: number;
}) {
    const { errors } = usePage().props;
    const form = useForm({ message: '' });
    const [pending, setPending] = useState<string | null>(null);
    const [handingOff, setHandingOff] = useState(false);
    const endRef = useRef<HTMLLIElement>(null);

    const last = messages.at(-1);
    const suggestHuman = last?.role === 'assistant' && last.suggests_handoff;

    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length, pending]);

    function send(event?: FormEvent) {
        event?.preventDefault();

        const text = form.data.message.trim();
        if (text === '' || form.processing) {
            return;
        }

        setPending(text);
        form.post(ask().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('message'),
            onFinish: () => setPending(null),
        });
    }

    // Enter sends, Shift+Enter starts a new line.
    function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            send();
        }
    }

    function talkToHuman() {
        setHandingOff(true);
        router.post(handoff().url, {}, { onFinish: () => setHandingOff(false) });
    }

    return (
        <Card className="flex max-w-3xl flex-col">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <BotIcon className="size-5" />
                    AI help assistant
                </CardTitle>
                <CardDescription>
                    You're chatting with an AI that answers from our help-centre articles. It can make mistakes and
                    can't change your tickets. Talk to a human at any time.
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-4">
                <ol className="max-h-[55vh] space-y-3 overflow-y-auto" aria-live="polite" aria-label="Conversation">
                    {messages.length === 0 && !pending && (
                        <li className="text-muted-foreground text-sm">
                            Ask a question, for example “How do I reset my password?”
                        </li>
                    )}
                    {messages.map((message) => (
                        <ChatMessageItem key={message.id} message={message} sources={sources} />
                    ))}
                    {pending && (
                        <>
                            <ChatMessageItem
                                message={{
                                    id: -1,
                                    role: 'user',
                                    content: pending,
                                    kb_article_ids: [],
                                    suggests_handoff: false,
                                    created_at: '',
                                }}
                                sources={sources}
                            />
                            <li className="text-muted-foreground text-sm" role="status">
                                The assistant is answering…
                            </li>
                        </>
                    )}
                    <li ref={endRef} aria-hidden />
                </ol>

                <form onSubmit={send} className="space-y-2">
                    <TextAreaInput
                        name="message"
                        label="Your question"
                        value={form.data.message}
                        onChange={(event) => form.setData('message', event.target.value)}
                        onKeyDown={onKeyDown}
                        error={form.errors.message}
                        maxLength={maxLength}
                        rows={3}
                        disabled={form.processing}
                        placeholder="Type your question — Enter to send, Shift+Enter for a new line"
                    />
                    <div className="flex justify-end">
                        <SubmitButton processing={form.processing}>Send</SubmitButton>
                    </div>
                </form>
            </CardContent>

            <CardFooter className="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between">
                <InputError message={errors.chat} />
                <div className="flex flex-wrap justify-end gap-2 sm:ml-auto">
                    {messages.length > 0 && (
                        <Button variant="ghost" onClick={() => router.post(restart().url)} disabled={form.processing}>
                            <RotateCcwIcon />
                            New conversation
                        </Button>
                    )}
                    <Button
                        variant={suggestHuman ? 'default' : 'outline'}
                        onClick={talkToHuman}
                        disabled={handingOff || form.processing || messages.length === 0}
                    >
                        <UserRoundIcon />
                        Talk to a human
                    </Button>
                </div>
            </CardFooter>
        </Card>
    );
}
