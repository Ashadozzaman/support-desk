import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed py-16 text-center">
            {Icon && <Icon className="text-muted-foreground size-10" />}
            <p className="font-medium">{title}</p>
            {description && <p className="text-muted-foreground text-sm">{description}</p>}
            {action}
        </div>
    );
}
