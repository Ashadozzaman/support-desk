import type { FormHTMLAttributes, ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export function FormCard({
    title,
    description,
    footer,
    children,
    ...props
}: FormHTMLAttributes<HTMLFormElement> & {
    title: string;
    description?: string;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
            </CardHeader>
            <form {...props}>
                <CardContent className="space-y-6">{children}</CardContent>
                {footer && <CardFooter className="justify-end gap-2">{footer}</CardFooter>}
            </form>
        </Card>
    );
}
