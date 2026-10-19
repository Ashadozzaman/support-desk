import {
    AlertTriangleIcon,
    BotIcon,
    CoinsIcon,
    MessagesSquareIcon,
    ShieldCheckIcon,
    TimerIcon,
    UserRoundIcon,
} from 'lucide-react';
import { StatCard } from '@/components/cards/StatCard';
import type { AiReport } from '@/types/report';

export function AiReportView({ report }: { report: AiReport }) {
    const { chats, calls } = report;

    return (
        <div className="flex flex-col gap-4">
            <p className="text-muted-foreground text-sm">
                Conversations started in the period. Deflected = closed by the customer without asking for a human.
                Priority, category and agent filters don't apply here.
            </p>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard label="Conversations" value={chats.total.toLocaleString()} icon={MessagesSquareIcon} />
                <StatCard
                    label="Deflection rate"
                    value={chats.deflection_pct === null ? '—' : `${chats.deflection_pct}%`}
                    icon={ShieldCheckIcon}
                />
                <StatCard label="Handed off to a human" value={chats.handed_off.toLocaleString()} icon={UserRoundIcon} />
                <StatCard label="AI calls" value={calls.total.toLocaleString()} icon={BotIcon} />
                <StatCard
                    label="Failed / refused calls"
                    value={`${calls.failed.toLocaleString()} / ${calls.refused.toLocaleString()}`}
                    icon={AlertTriangleIcon}
                />
                <StatCard label="Tokens used" value={calls.tokens.toLocaleString()} icon={CoinsIcon} />
                <StatCard
                    label="Avg answer time"
                    value={calls.avg_latency_ms === null ? '—' : `${(calls.avg_latency_ms / 1000).toFixed(1)} s`}
                    icon={TimerIcon}
                />
            </div>
        </div>
    );
}
