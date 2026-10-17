<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Computed, not stored: derived from status/now()/sla_warning_at/sla_due_at/resolved_at
 * (ARCHITECTURE.md §8.3). Unbacked because it never needs a DB representation.
 */
enum SlaState
{
    case WithinSla;
    case Approaching;
    case Breached;
    case Met;
    case MetLate;
}
