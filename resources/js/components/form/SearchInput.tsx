import { SearchIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Debounced text input. Not wired to a backend filter yet — keyword
 * search ships in Step 12 (TicketFilterData / scopeFilter).
 */
export function SearchInput({
    value,
    onSearch,
    placeholder = 'Search…',
    delay = 300,
    className,
}: {
    value?: string;
    onSearch: (value: string) => void;
    placeholder?: string;
    delay?: number;
    className?: string;
}) {
    const [term, setTerm] = useState(value ?? '');

    useEffect(() => {
        const handle = setTimeout(() => onSearch(term), delay);

        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className={cn('relative', className)}>
            <SearchIcon className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
            <Input
                type="search"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={placeholder}
                className="pl-8"
            />
        </div>
    );
}
