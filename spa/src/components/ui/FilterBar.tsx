import { useState, type FormEvent } from 'react';
import { Search } from 'lucide-react';
import { cn } from '@/lib/cn';
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
  className?: string;
}

export function FilterBar({
  filters = [],
  values = {},
  onFilter,
  onSearch,
  searchPlaceholder = 'Search…',
  className,
}: FilterBarProps) {
  const [searchValue, setSearchValue] = useState<string>(((values.search as string) ?? ''));

  const submit = (e: FormEvent) => {
    e.preventDefault();
    onSearch?.(searchValue);
  };

  return (
    <div className={cn('flex items-center gap-2 px-5 py-3 border-b border-default flex-wrap', className)}>
      <form onSubmit={submit} className="flex items-center h-8 w-64 rounded-md border border-default bg-canvas px-2.5 focus-within:ring-2 focus-within:ring-accent">
        <Search size={14} className="text-muted shrink-0" />
        <input
          type="search"
          value={searchValue}
          onChange={(e) => setSearchValue(e.target.value)}
          placeholder={searchPlaceholder}
          className="flex-1 h-full px-2 text-sm bg-transparent outline-none placeholder:text-text-subtle"
        />
      </form>

      {filters.map((f) => (
        <Select
          key={f.key}
          aria-label={f.label}
          value={String(values[f.key] ?? '')}
          onChange={(e) => onFilter?.(f.key, e.target.value || undefined)}
          containerClassName="min-w-[160px]"
        >
          <option value="">{f.placeholder ?? f.label}</option>
          {f.options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      ))}
    </div>
  );
}
