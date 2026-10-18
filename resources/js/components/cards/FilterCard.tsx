import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';

export function FilterCard({ children }: { children: ReactNode }) {
    return (
        <Card>
            <CardContent className="flex flex-wrap items-end gap-4">{children}</CardContent>
        </Card>
    );
}
