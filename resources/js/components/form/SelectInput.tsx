import { CheckIcon, ChevronsUpDownIcon } from 'lucide-react';
import { useState } from 'react';
import { FormField } from '@/components/form/FormField';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type SelectOptionBadge = {
    text: string;
    variant?: 'secondary' | 'destructive' | 'outline';
};

export type SelectOption = {
    value: string;
    label: string;
    /** Small extra info shown after the label (e.g. an agent's load); not searched. */
    badges?: SelectOptionBadge[];
};

/**
 * Searchable combobox (not a plain dropdown) — with a few hundred/thousand
 * categories, agents or customers a plain <select> renders every row and
 * gives the user no way to narrow it down. CommandItem matches by `label`
 * (what the user sees and types), the value passed to onChange is always
 * the option's own `value`.
 */
export function SelectInput({
    name,
    label,
    error,
    value,
    placeholder,
    options,
    disabled,
    onChange,
}: {
    name: string;
    label: string;
    error?: string;
    value: string | undefined;
    placeholder?: string;
    options: SelectOption[];
    disabled?: boolean;
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);

    return (
        <FormField htmlFor={name} label={label} error={error}>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={name}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-invalid={!!error}
                        disabled={disabled}
                        className="w-full justify-between font-normal"
                    >
                        <span className={cn('flex min-w-0 items-center gap-2', !selected && 'text-muted-foreground')}>
                            <span className="truncate">{selected?.label ?? placeholder ?? 'Select…'}</span>
                            {selected && <OptionBadges badges={selected.badges} />}
                        </span>
                        <ChevronsUpDownIcon className="ml-2 size-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
                    <Command>
                        <CommandInput placeholder="Search…" />
                        <CommandList>
                            <CommandEmpty>No results found.</CommandEmpty>
                            <CommandGroup>
                                {options.map((option) => (
                                    <CommandItem
                                        key={option.value}
                                        value={option.label}
                                        onSelect={() => {
                                            onChange(option.value);
                                            setOpen(false);
                                        }}
                                    >
                                        <CheckIcon
                                            className={cn(
                                                'size-4',
                                                option.value === value ? 'opacity-100' : 'opacity-0',
                                            )}
                                        />
                                        <span className="truncate">{option.label}</span>
                                        <OptionBadges badges={option.badges} />
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </FormField>
    );
}

function OptionBadges({ badges }: { badges?: SelectOptionBadge[] }) {
    if (!badges || badges.length === 0) {
        return null;
    }

    return (
        <span className="ml-auto flex shrink-0 items-center gap-1">
            {badges.map((badge) => (
                <Badge key={badge.text} variant={badge.variant ?? 'secondary'} className="px-1.5 py-0 text-[10px] font-normal">
                    {badge.text}
                </Badge>
            ))}
        </span>
    );
}
