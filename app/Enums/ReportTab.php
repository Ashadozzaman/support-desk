<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tabs of the Reports page. Each request computes only the active tab.
 */
enum ReportTab: string
{
    case Overview = 'overview';
    case Sla = 'sla';
    case ResponseResolution = 'response';
    case Agents = 'agents';
    case Aging = 'aging';
    case Categories = 'categories';
}
