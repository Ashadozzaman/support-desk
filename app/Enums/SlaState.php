<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

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

    /**
     * Pure function over primitives/Carbon (not a Ticket) so this stays a
     * small helper rather than coupling the enum to the Eloquent model —
     * same shape as TicketStatus::canTransitionTo().
     */
    public static function resolve(
        TicketStatus $status,
        ?bool $slaMet,
        ?CarbonInterface $slaDueAt,
        ?CarbonInterface $slaWarningAt,
        ?CarbonInterface $now = null,
    ): ?self {
        if ($slaDueAt === null) {
            return null;
        }

        if (in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            return $slaMet ? self::Met : self::MetLate;
        }

        $now ??= now();

        if ($slaDueAt->lessThanOrEqualTo($now)) {
            return self::Breached;
        }

        if ($slaWarningAt !== null && $slaWarningAt->lessThanOrEqualTo($now)) {
            return self::Approaching;
        }

        return self::WithinSla;
    }

    /**
     * Maps the query-string key used by the ticket list filter (and the
     * frontend's SlaStateValue) back to a case — the only place this enum
     * touches a string, since the request/URL is the boundary.
     */
    public static function fromKey(string $key): self
    {
        return match ($key) {
            'within' => self::WithinSla,
            'approaching' => self::Approaching,
            'breached' => self::Breached,
            'met' => self::Met,
            'met_late' => self::MetLate,
            default => throw new \ValueError("Invalid SLA state key: {$key}"),
        };
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return ['within', 'approaching', 'breached', 'met', 'met_late'];
    }
}
