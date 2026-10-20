export type ChatMessageRole = 'user' | 'assistant';

export type ChatMessage = {
    id: number;
    role: ChatMessageRole;
    /** Plain text — rendered with whitespace-pre-wrap, never as HTML. */
    content: string;
    /** Articles the answer is based on, in the order the answer cites them ([1], [2], …). */
    kb_article_ids: number[];
    /** The assistant couldn't answer from the help centre. */
    suggests_handoff: boolean;
    created_at: string;
};

/** Keyed by article id (JSON object keys are strings). */
export type ChatSources = Record<string, { title: string; slug: string }>;
