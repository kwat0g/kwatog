import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { LuSearch, LuX } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import { Select } from './Select';
import { Toolbar } from './Toolbar';

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
 searchMaxLength?: number;
 /**
 * Optional From/To date pickers bound to two filter keys (e.g. from/to).
 * Emits `undefined` when a date is cleared so lists can drop the filter.
 */
 dateRange?: { fromKey: string; toKey: string; label?: string };
 /** Trailing controls (export, view switch) pinned to the right of the bar. */
 actions?: ReactNode;
 /**
  * Hide the search box. Set false when the endpoint has no search — the bar
  * always rendered one, so inventory/movements shipped a search field wired to
  * `onSearch={() => undefined}`: it accepted typing and did nothing, which
  * reads as broken rather than absent.
  */
 searchable?: boolean;
 resultCount?: number;
 className?: string;
 inset?: boolean;
}

/** Keystroke-to-request delay: long enough to skip mid-word, short enough to feel live. */
const SEARCH_DEBOUNCE_MS = 300;

export function FilterBar({
 filters = [],
 values = {},
 onFilter,
 onSearch,
 searchPlaceholder = 'Search…',
 searchMaxLength,
 dateRange,
 actions,
 className,
 inset = true,
 searchable = true,
 resultCount,
}: FilterBarProps) {
 const external = (values.search as string) ?? '';
 const [searchValue, setSearchValue] = useState<string>(external);
 const [showAllChips, setShowAllChips] = useState(false);
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
 const activeFilterCount = activeFilters.length + (hasActiveDate ? 1 : 0) + (searchValue !== '' ? 1 : 0);

 const filterChips = [
  ...activeFilters.map((filter) => {
   const raw = String(values[filter.key]);
   const option = filter.options.find((item) => String(item.value) === raw);
   return {
    key: filter.key,
    label: `${filter.label}: ${option?.label ?? raw}`,
    remove: () => onFilter?.(filter.key, undefined),
   };
  }),
  ...(searchValue ? [{ key: 'search', label: `Search: ${searchValue}`, remove: clearSearch }] : []),
  ...(dateRange
   ? dateKeys.flatMap((key) => {
      const value = String(values[key] ?? '');
      if (!value) return [];
      return [{
       key,
       label: `${dateRange.label ?? 'Date'} ${key === dateRange.fromKey ? 'from' : 'to'}: ${value}`,
       remove: () => onFilter?.(key, undefined),
      }];
     })
   : []),
 ];
 const visibleChips = showAllChips ? filterChips : filterChips.slice(0, 3);
 const hiddenChipCount = filterChips.length - visibleChips.length;

 const clearAll = () => {
 activeFilters.forEach((f) => onFilter?.(f.key, undefined));
 dateKeys.forEach((k) => onFilter?.(k, undefined));
 if (searchValue !== '') clearSearch();
 };

 return (
 <Toolbar
 layout="filter-bar"
 inset={inset}
 className={cn('py-3 border-b border-default', className)}
 start={(
 <>
 {searchable && (
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
 maxLength={searchMaxLength}
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
 )}

 {filters.map((f) => (
 <Select
 key={f.key}
 aria-label={f.label}
 value={String(values[f.key] ?? '')}
   searchable
   fieldSize="md"
 onChange={(e) => onFilter?.(f.key, e.target.value || undefined)}
 containerClassName="min-w-[160px]"
 >
 {f.options.some((o) => String(o.value) === '') ? null : (
 <option value="">{f.placeholder ?? `${f.label}: All`}</option>
 )}
 {f.options.map((o) => (
 <option key={o.value} value={o.value}>
          {String(o.value) === '' && !o.label.startsWith(`${f.label}:`)
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
  <div role="group" aria-label="Active filters" className="flex min-w-0 flex-wrap items-center gap-1.5">
   {visibleChips.map((chip) => (
    <button
     key={chip.key}
     type="button"
     onClick={chip.remove}
     aria-label={`Remove ${chip.label}`}
     className={cn('inline-flex min-h-7 max-w-64 items-center gap-1.5 border border-default bg-subtle px-2 text-caption text-primary hover:border-strong', focusRing)}
    >
     <span className="truncate">{chip.label}</span>
     <LuX size={12} aria-hidden="true" />
    </button>
   ))}
   {hiddenChipCount > 0 && (
    <button
     type="button"
     aria-expanded={showAllChips}
     onClick={() => setShowAllChips(true)}
     className={cn('min-h-7 px-1.5 text-caption text-secondary hover:text-primary', focusRing)}
    >
     +{hiddenChipCount} more
    </button>
   )}
   <span className="text-caption text-muted" aria-live="polite" aria-atomic="true">
    {activeFilterCount} active {activeFilterCount === 1 ? 'filter' : 'filters'}
   </span>
   <button
    type="button"
    onClick={clearAll}
    className={cn('min-h-7 px-1.5 text-caption text-muted hover:text-primary cursor-pointer', focusRing)}
   >
    Clear all
   </button>
  </div>
 )}

 {resultCount !== undefined && (
  <span className="ml-auto text-caption tabular-nums text-muted" aria-live="polite">
   {resultCount.toLocaleString()} results
  </span>
 )}

 </>
 )}
 end={actions}
 />
 );
}
