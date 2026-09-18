import { CheckIcon, ChevronDownIcon, SearchIcon, XIcon } from 'lucide-react';
import {
    type KeyboardEvent,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import { cn } from '@/lib/utils';

export type SearchableOption = {
    value: string;
    label: string;
    /** A second, quieter line under the label — a code, a unit, a client. */
    hint?: string;
    disabled?: boolean;
};

type SearchableSelectProps = {
    id?: string;
    /** Rendered as a hidden input so plain HTML forms carry the value too. */
    name?: string;
    value: string;
    onValueChange: (value: string) => void;
    options: SearchableOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    /** Above this many options the list gets a search box. */
    searchThreshold?: number;
    clearable?: boolean;
    disabled?: boolean;
    className?: string;
    'aria-invalid'?: boolean;
};

/**
 * A select that can be searched once the list is long.
 *
 * Short lists behave like an ordinary select. Past the threshold a search
 * box appears at the top of the list and narrows it as you type — every
 * word typed has to appear somewhere in the label, in any order, so
 * "betaine coco" finds "Cocamidopropyl Betaine". Keyboard: arrows move,
 * Enter picks, Escape closes.
 */
export function SearchableSelect({
    id,
    name,
    value,
    onValueChange,
    options,
    placeholder = 'Choose',
    searchPlaceholder = 'Type to search…',
    emptyText = 'Nothing matches',
    searchThreshold = 10,
    clearable = false,
    disabled = false,
    className,
    'aria-invalid': ariaInvalid,
}: SearchableSelectProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const searchInput = useRef<HTMLInputElement>(null);
    const list = useRef<HTMLUListElement>(null);
    const listId = useId();

    const searchable = options.length > searchThreshold;
    const selected = options.find((o) => o.value === value) ?? null;

    const shown = useMemo(() => {
        const words = query.toLowerCase().split(/\s+/).filter(Boolean);

        if (words.length === 0) {
            return options;
        }

        return options.filter((o) => {
            const haystack = `${o.label} ${o.hint ?? ''}`.toLowerCase();

            return words.every((w) => haystack.includes(w));
        });
    }, [options, query]);

    // Open: clear the last search, land on the current choice, focus the box.
    useEffect(() => {
        if (!open) {
            return;
        }

        setQuery('');
        const current = options.findIndex((o) => o.value === value);
        setActive(current >= 0 ? current : 0);

        const frame = requestAnimationFrame(() => searchInput.current?.focus());

        return () => cancelAnimationFrame(frame);
    }, [open, options, value]);

    // A click anywhere else closes the list.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);

        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    // Keep the highlighted row in view as the arrows move it.
    useEffect(() => {
        if (!open) {
            return;
        }

        const row = list.current?.children[active] as HTMLElement | undefined;
        row?.scrollIntoView({ block: 'nearest' });
    }, [active, open, shown.length]);

    const choose = (option: SearchableOption) => {
        if (option.disabled) {
            return;
        }

        onValueChange(option.value);
        setOpen(false);
    };

    const move = (delta: number) => {
        if (shown.length === 0) {
            return;
        }

        setActive((i) => (i + delta + shown.length) % shown.length);
    };

    const onKeyDown = (e: KeyboardEvent<HTMLElement>) => {
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (!open) {
                    setOpen(true);
                } else {
                    move(1);
                }
                break;
            case 'ArrowUp':
                e.preventDefault();
                if (open) {
                    move(-1);
                }
                break;
            case 'Enter':
                if (open) {
                    e.preventDefault();
                    const option = shown[active];
                    if (option) {
                        choose(option);
                    }
                } else if (e.currentTarget.tagName === 'BUTTON') {
                    e.preventDefault();
                    setOpen(true);
                }
                break;
            case 'Escape':
                if (open) {
                    e.preventDefault();
                    setOpen(false);
                }
                break;
            case 'Tab':
                setOpen(false);
                break;
            default:
                break;
        }
    };

    return (
        <div ref={root} className={cn('relative', className)}>
            {name !== undefined && (
                <input type="hidden" name={name} value={value} />
            )}

            <button
                type="button"
                id={id}
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-haspopup="listbox"
                aria-invalid={ariaInvalid}
                disabled={disabled}
                onClick={() => setOpen((o) => !o)}
                onKeyDown={onKeyDown}
                data-placeholder={selected ? undefined : ''}
                className={cn(
                    'border-input data-[placeholder]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 dark:hover:bg-input/50 flex h-9 w-full items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-left text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
                )}
            >
                <span className="min-w-0 flex-1 truncate">
                    {selected ? selected.label : placeholder}
                </span>
                {clearable && selected && !disabled ? (
                    <span
                        role="button"
                        aria-label="Clear"
                        tabIndex={-1}
                        onClick={(e) => {
                            e.stopPropagation();
                            onValueChange('');
                        }}
                        className="text-muted-foreground hover:text-foreground -mr-1 rounded p-0.5"
                    >
                        <XIcon className="size-4" />
                    </span>
                ) : (
                    <ChevronDownIcon className="size-4 shrink-0 opacity-50" />
                )}
            </button>

            {open && (
                <div className="bg-popover text-popover-foreground absolute z-50 mt-1 w-full min-w-[12rem] overflow-hidden rounded-md border shadow-md">
                    {searchable && (
                        <div className="flex items-center gap-2 border-b px-2">
                            <SearchIcon className="text-muted-foreground size-4 shrink-0" />
                            <input
                                ref={searchInput}
                                type="text"
                                value={query}
                                placeholder={searchPlaceholder}
                                onChange={(e) => {
                                    setQuery(e.target.value);
                                    setActive(0);
                                }}
                                onKeyDown={onKeyDown}
                                aria-controls={listId}
                                aria-autocomplete="list"
                                className="placeholder:text-muted-foreground h-9 w-full bg-transparent text-sm outline-none"
                            />
                            <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                {shown.length}/{options.length}
                            </span>
                        </div>
                    )}

                    <ul
                        ref={list}
                        id={listId}
                        role="listbox"
                        tabIndex={searchable ? -1 : 0}
                        onKeyDown={searchable ? undefined : onKeyDown}
                        className="max-h-72 overflow-y-auto p-1 outline-none"
                    >
                        {shown.length === 0 ? (
                            <li className="text-muted-foreground px-2 py-6 text-center text-sm">
                                {emptyText}
                            </li>
                        ) : (
                            shown.map((option, i) => {
                                const isSelected = option.value === value;

                                return (
                                    <li
                                        key={option.value}
                                        role="option"
                                        aria-selected={isSelected}
                                        aria-disabled={option.disabled}
                                        onMouseEnter={() => setActive(i)}
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => choose(option)}
                                        className={cn(
                                            'relative flex cursor-default items-start gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm select-none',
                                            i === active &&
                                                'bg-accent text-accent-foreground',
                                            option.disabled &&
                                                'pointer-events-none opacity-50',
                                        )}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate">
                                                {option.label}
                                            </span>
                                            {option.hint && (
                                                <span className="text-muted-foreground block truncate text-xs">
                                                    {option.hint}
                                                </span>
                                            )}
                                        </span>
                                        {isSelected && (
                                            <span className="absolute right-2 flex size-3.5 items-center justify-center">
                                                <CheckIcon className="size-4" />
                                            </span>
                                        )}
                                    </li>
                                );
                            })
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
}
