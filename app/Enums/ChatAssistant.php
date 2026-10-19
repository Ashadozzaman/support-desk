<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which assistant a conversation belongs to. Support = the customer help
 * chatbot (help-centre articles only, Step 24). Data = the staff assistant
 * that answers from read-only ticket/report lookups (Step 24b).
 */
enum ChatAssistant: string
{
    case Support = 'support';
    case Data = 'data';
}
