import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { LuSearch, LuX } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import { Select } from './Select';

export interface FilterOption {
 value: string;
 label: string;
}

export interface FilterConfig {
 key: string;
 label: string;
 type: 'select';
 options: FilterOption[];
 placeholder?: string;
}

interface FilterBarProps {
 filters?: FilterConfig[];
 values?: Record<string, unknown>;
 onFilter?: (key: string, value: unknown) => void;
 onSearch?: (search: string) => void;
 searchPlaceholder?: string;
 /**
 * Optional From/To date pickers bound to two filter keys (e.g. from/to).
 * Emits `undefined` when a date is cleared so lists can drop the filter.
 */
 dateRange?: { fromKey: string; toKey: string; label?: string };
 /** Trailing controls (export, view switch) pinned to the right of the bar. */
 actions?: ReactNode;
 className?: string;
}

/** Keystroke-to-request delay: long enough to skip mid-word, short enough to feel live. */
const SEARCH_DEBOUNCE_MS = 300;

export function FilterBar({
 filters = [],
 values = {},
 onFilter,
 onSearch,
 searchPlaceholder = 'Search…',
 dateRange,
 actions,
 className,
}: FilterBarProps) {
 const external = (values.search as string) ?? '';
 const [searchValue, setSearchValue] = useState<string>(external);
 const inputRef = useRef<HTMLInputElement>(null);
 const emitted = useRef(external);

 // Follow the parent when it resets or restores filters (URL state, a reset
 // button elsewhere on the page) without fighting the user mid-type.
 useEffect(() => {
 if (external !== emitted.current && document.activeElement !== inputRef.current) {
 emitted.current = external;
 setSearchValue(external);
 }
 }, [external]);

 // Search as you type -- the old bar only searched on Enter, with nothing on
 // screen saying so. Enter still works and just skips the wait.
 useEffect(() => {
 if (searchValue === emitted.current) return;
 const t = setTimeout(() => {
 emitted.current = searchValue;
 onSearch?.(searchValue);
 }, SEARCH_DEBOUNCE_MS);
 return () => clearTimeout(t);
 // eslint-disable-next-line react-hooks/exhaustive-deps
 }, [searchValue]);

 const submit = (e: FormEvent) => {
 e.preventDefault();
 emitted.current = searchValue;
 onSearch?.(searchValue);
 };

 const clearSearch = () => {
 setSearchValue('');
 emitted.current = '';
 onSearch?.('');
 inputRef.current?.focus();
 };

 const dateKeys = dateRange ? [dateRange.fromKey, dateRange.toKey] : [];
 const activeFilters = filters.filter((f) => {
 const v = values[f.key];
 return v !== undefined && v !== null && v !== '';
 });
 const hasActiveDate = dateKeys.some((k) => {
 const v = values[k];
 return v !== undefined && v !== null && v !== '';
 });
 const hasActive = activeFilters.length > 0 || hasActiveDate || searchValue !== '';

 const clearAll = () => {
 activeFilters.forEach((f) => onFilter?.(f.key, undefined));
 dateKeys.forEach((k) => onFilter?.(k, undefined));
 if (searchValue !== '') clearSearch();
 };

 return (
 <div className={cn('flex items-center gap-2 px-5 py-3 border-b border-default flex-wrap', className)}>
 <form
 onSubmit={submit}
 role="search"
 className="flex items-center h-8 w-64 rounded-md border border-default bg-canvas px-2.5 transition-colors duration-fast focus-within:ring-2 focus-within:ring-accent focus-within:border-accent"
 >
 <LuSearch size={14} className="text-muted shrink-0" aria-hidden />
 <input
 ref={inputRef}
 type="text"
 value={searchValue}
 onChange={(e) => setSearchValue(e.target.value)}
 placeholder={searchPlaceholder}
 aria-label={searchPlaceholder}
 className="flex-1 min-w-0 h-full px-2 text-sm bg-transparent outline-none placeholder:text-subtle"
 />
 {searchValue && (
 <button
 type="button"
 onClick={clearSearch}
 aria-label="Clear search"
 className={cn('shrink-0 text-muted hover:text-primary rounded-sm cursor-pointer', focusRing)}
 >
 <LuX size={13} />
 </button>
 )}
 </form>

 {filters.map((f) => (
 <Select
 key={f.key}
 aria-label={f.label}
 value={String(values[f.key] ?? '')}
 onChange={(e) => onFilter?.(f.key, e.target.value || undefined)}
 containerClassName="min-w-[160px]"
 >
 {f.options.some((o) => String(o.value) === '') ? null : (
 <option value="">{f.placeholder ?? `${f.label}: All`}</option>
 )}
 {f.options.map((o) => (
 <option key={o.value} value={o.value}>
 {String(o.value) === '' && (o.label === 'All' || o.label === 'Any')
 ? `${f.label}: ${o.label}`
 : o.label}
 </option>
 ))}
 </Select>
 ))}

 {dateRange && (
 <div className="flex items-center gap-1.5">
 <span className="text-xs font-medium uppercase tracking-wide text-subtle whitespace-nowrap">
 {dateRange.label ?? 'Date'}
 </span>
 <input
 type="date"
 aria-label={`${dateRange.label ?? 'Date'} from`}
 value={String(values[dateRange.fromKey] ?? '')}
 onChange={(e) => onFilter?.(dateRange.fromKey, e.target.value || undefined)}
 className="h-8 rounded-md border border-default bg-canvas px-2 text-sm font-mono tabular-nums text-primary outline-none transition-colors duration-fast focus:border-accent focus:ring-2 focus:ring-accent"
 />
 <span className="text-subtle" aria-hidden>
 →
 </span>
 <input
 type="date"
 aria-label={`${dateRange.label ?? 'Date'} to`}
 value={String(values[dateRange.toKey] ?? '')}
 onChange={(e) => onFilter?.(dateRange.toKey, e.target.value || undefined)}
 className="h-8 rounded-md border border-default bg-canvas px-2 text-sm font-mono tabular-nums text-primary outline-none transition-colors duration-fast focus:border-accent focus:ring-2 focus:ring-accent"
 />
 </div>
 )}

 {hasActive && (
 <button
 type="button"
 onClick={clearAll}
 className={cn('h-8 px-2 text-xs text-muted hover:text-primary rounded-md cursor-pointer', focusRing)}
 >
 Clear all
 </button>
 )}

 {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}
 </div>
 );
}
