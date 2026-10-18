import type { TicketPriorityValue } from '@/types/ticket';

/**
 * dataviz skill's status palette (good/warning/serious/critical) — fixed,
 * never themed, same hex in light and dark. Priority severity maps onto it
 * directly (low→good … critical→critical). Adjacent steps fall below the
 * skill's normal-vision floor on their own, so every bar that uses these
 * colors must also carry a visible text label (axis name + value) — never
 * color alone.
 */
export const PRIORITY_CHART_COLORS: Record<TicketPriorityValue, string> = {
    low: '#0ca30c',
    medium: '#fab219',
    high: '#ec835a',
    critical: '#d03b3b',
};
