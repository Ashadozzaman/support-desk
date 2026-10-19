import { Link, usePage } from '@inertiajs/react';
import { Clock, LifeBuoy, ShieldCheck, Ticket } from 'lucide-react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const highlights = [
    { icon: Ticket, text: 'Raise a ticket and follow it from open to resolved' },
    { icon: Clock, text: 'Every ticket is tracked against a response deadline' },
    { icon: ShieldCheck, text: 'Your conversations and files stay private' },
];

export default function AuthSupportLayout({ children, title, description }: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="grid min-h-svh lg:grid-cols-2">
            <aside className="hidden flex-col justify-between bg-primary p-10 text-primary-foreground lg:flex">
                <Link href={home()} className="flex items-center gap-2 text-lg font-semibold">
                    <LifeBuoy className="size-7" />
                    {name}
                </Link>

                <div className="space-y-6">
                    <h2 className="text-3xl font-semibold leading-tight">
                        Help when you need it,
                        <br />
                        on time.
                    </h2>
                    <ul className="space-y-4">
                        {highlights.map(({ icon: Icon, text }) => (
                            <li key={text} className="flex items-start gap-3 text-sm opacity-90">
                                <Icon className="mt-0.5 size-5 shrink-0" />
                                {text}
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="text-xs opacity-70">© {new Date().getFullYear()} {name}</p>
            </aside>

            <main className="flex flex-col items-center justify-center gap-8 bg-background px-4 py-10 sm:px-6">
                <Link href={home()} className="flex items-center gap-2 text-lg font-semibold lg:hidden">
                    <LifeBuoy className="size-7" />
                    {name}
                </Link>

                <div className="w-full max-w-sm space-y-6">
                    <div className="space-y-2">
                        <h1 className="text-2xl font-semibold">{title}</h1>
                        {description && <p className="text-sm text-muted-foreground">{description}</p>}
                    </div>
                    {children}
                </div>
            </main>
        </div>
    );
}
