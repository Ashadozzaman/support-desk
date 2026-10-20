<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of one provider call. Refused = the model declined (HTTP 200,
 * stop_reason "refusal"): not a provider fault, so it doesn't count towards
 * the circuit breaker.
 */
enum AiInteractionStatus: string
{
    case Succeeded = 'succeeded';
    case Refused = 'refused';
    case Failed = 'failed';
}
