import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

export function SubmitButton({
    processing,
    children,
    ...props
}: ComponentProps<typeof Button> & { processing?: boolean }) {
    return (
        <Button type="submit" disabled={processing} {...props}>
            {processing && <Spinner />}
            {children}
        </Button>
    );
}
