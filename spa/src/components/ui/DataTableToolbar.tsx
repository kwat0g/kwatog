import { useState } from 'react';
import { Settings2, Rows3, Rows2, Rows4 } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Checkbox } from './Checkbox';
import type { Column } from './DataTable';
import type { TableDensity } from '@/stores/tablePrefsStore';

interface ToolbarProps<T> {
  columns: Column<T>[];
  density: TableDensity;
  onDensityChange?: (d: TableDensity) => void;
  tableKey?: string;
  enableColumnVisibilityToggle?: boolean;
  hiddenColumnKeys: Set<string>;
  toggleColumnVisibility: (key: string) => void;
}

export function DataTableToolbar<T>({
  columns,
  density,
  onDensityChange,
  tableKey,
  enableColumnVisibilityToggle,
  hiddenColumnKeys,
  toggleColumnVisibility,
}: ToolbarProps<T>) {
  const [visMenuOpen, setVisMenuOpen] = useState(false);
  const showVisibilityToggle = enableColumnVisibilityToggle ?? !!tableKey;
  const showDensity = !!onDensityChange || !!tableKey;

  if (!showVisibilityToggle && !showDensity) return null;

  return (
    <div className="flex justify-end mb-2 gap-1 relative">
      {showVisibilityToggle && (
        <>
          <button
            type="button"
            title="Customize columns"
            aria-label="Customize columns"
            onClick={() => setVisMenuOpen((v) => !v)}
            className="h-7 w-7 inline-flex items-center justify-center rounded-md border border-default text-muted hover:bg-elevated transition-colors duration-fast cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
          >
            <Settings2 size={14} />
          </button>
          {visMenuOpen && (
            <div
              className="absolute right-0 top-8 z-30 w-56 bg-elevated border border-default rounded-md shadow-menu py-2"
              onMouseLeave={() => setVisMenuOpen(false)}
            >
              <div className="px-2.5 pb-1.5 text-2xs uppercase tracking-wider text-muted font-medium">
                Columns
              </div>
              {columns
                .filter((c) => c.togglable !== false)
                .map((c) => (
                  <label
                    key={c.key}
                    className="flex items-center gap-2 px-2.5 h-7 text-xs cursor-pointer hover:bg-subtle"
                  >
                    <Checkbox
                      checked={!hiddenColumnKeys.has(c.key)}
                      onChange={() => toggleColumnVisibility(c.key)}
                    />
                    <span className="text-primary">
                      {typeof c.header === 'string' ? c.header : c.key}
                    </span>
                  </label>
                ))}
            </div>
          )}
        </>
      )}
      {showDensity &&
        (['compact', 'default', 'spacious'] as TableDensity[]).map((d) => {
          const Icon = d === 'compact' ? Rows4 : d === 'spacious' ? Rows2 : Rows3;
          return (
            <button
              key={d}
              title={`${d} rows`}
              aria-label={`${d} density`}
              aria-pressed={density === d}
              onClick={() => onDensityChange?.(d)}
              className={cn(
                'h-7 w-7 inline-flex items-center justify-center rounded-md border border-default transition-colors duration-fast cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                density === d ? 'bg-elevated text-primary' : 'text-muted hover:bg-elevated',
              )}
            >
              <Icon size={14} />
            </button>
          );
        })}
    </div>
  );
}
