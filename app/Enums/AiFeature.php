<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every place the app calls a model (ENHANCEMENTS.md §3). Stored in
 * ai_interactions.feature, and used as the rate-limit key and to pick the model.
 */
enum AiFeature: string
{
    case TicketTriage = 'ticket_triage';
    case ReplyDraft = 'reply_draft';
    case TicketSummary = 'ticket_summary';
    case SupportChat = 'support_chat';
    case DataAssistant = 'data_assistant';

    public function model(): string
    {
        $override = config('ai.models.'.$this->value);

        return is_string($override) && $override !== '' ? $override : (string) config('ai.model');
    }
}
