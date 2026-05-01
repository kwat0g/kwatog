import { useMemo, useState, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Rows3, Rows2, Rows4 } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Button } from './Button';
import { Checkbox } from './Checkbox';
import type { PaginationMeta } from '@/types';

// ─── Public types ──────────────────────────────────────────────

export type ColumnAlign = 'left' | 'right' | 'center';

export interface Column<T> {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  /** Enable header click → onSort. */
  sortable?: boolean;
  /** Right-align numeric cells. */
  align?: ColumnAlign;
  /** Tailwind width class (e.g. `w-32`, `min-w-[160px]`). */
  className?: string;
  /** Hide by default; user can toggle in column visibility menu (TODO Sprint 2). */
  defaultHidden?: boolean;
}

export type Density = 'compact' | 'default' | 'spacious';

export interface BulkAction<T> {
  label: ReactNode;
  onClick: (rows: T[]) => void;
  variant?: 'primary' | 'secondary' | 'danger';
  icon?: ReactNode;
}

export interface DataTableProps<T> {
  columns: Column<T>[];
  data: T[];
  meta?: PaginationMeta;
  onPageChange?: (page: number) => void;
  onSort?: (sort: string, direction: 'asc' | 'desc') => void;
  currentSort?: string;
  currentDirection?: 'asc' | 'desc';
  onRowClick?: (row: T) => void;
  selectable?: boolean;
  bulkActions?: BulkAction<T>[];
  density?: Density;
  onDensityChange?: (density: Density) => void;
  /** Stable identifier per row for selection state. Defaults to `(row as any).id`. */
  getRowId?: (row: T) => string;
  className?: string;
}

// ─── Density mapping ───────────────────────────────────────────

const rowHeight: Record<Density, string> = {
  compact: 'h-7',
  default: 'h-8',
  spacious: 'h-10',
};

const alignClass: Record<ColumnAlign, string> = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

// ─── Component ────────────────────────────────────────────────

export function DataTable<T>({
  columns,
  data,
  meta,
  onPageChange,
  onSort,
  currentSort,
  currentDirection,
  onRowClick,
  selectable,
  bulkActions,
  density = 'default',
  onDensityChange,
  getRowId,
  className,
}: DataTableProps<T>) {
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const idOf = useMemo(
    () => getRowId ?? ((row: T) => String((row as { id?: string }).id ?? '')),
    [getRowId],
  );

  const allOnPageSelected = data.length > 0 && data.every((r) => selected.has(idOf(r)));

  const toggleAll = () => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (allOnPageSelected) {
        data.forEach((r) => next.delete(idOf(r)));
      } else {
        data.forEach((r) => next.add(idOf(r)));
      }
      return next;
    });
  };

  const toggleOne = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const visibleColumns = columns.filter((c) => !c.defaultHidden);
  const selectedRows = data.filter((r) => selected.has(idOf(r)));

  const sortIndicator = (col: Column<T>) => {
    if (!col.sortable) return null;
    const active = currentSort === col.key;
    return (
      <span className={cn('inline-block ml-1', !active && 'opacity-30')}>
        {active && currentDirection === 'asc' ? <ArrowUp size={10} /> : <ArrowDown size={10} />}
      </span>
    );
  };

  const handleHeaderClick = (col: Column<T>) => {
    if (!col.sortable || !onSort) return;
    const nextDir: 'asc' | 'desc' =
      currentSort === col.key && currentDirection === 'asc' ? 'desc' : 'asc';
    onSort(col.key, nextDir);
  };

  return (
    <div className={cn('flex flex-col', className)}>
      {/* Bulk action bar */}
      {selectable && selectedRows.length > 0 && (
        <div className="flex items-center justify-between px-3 py-2 bg-info-bg/40 border border-default rounded-md mb-2">
          <div className="text-xs text-primary">
            <span className="font-mono tabular-nums">{selectedRows.length}</span> selected
          </div>
          <div className="flex items-center gap-1.5">
            {bulkActions?.map((a, i) => (
              <Button key={i} size="sm" variant={a.variant ?? 'secondary'} icon={a.icon} onClick={() => a.onClick(selectedRows)}>
                {a.label}
              </Button>
            ))}
            <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
              Clear
            </Button>
          </div>
        </div>
      )}

      {/* Toolbar (density toggle) */}
      {onDensityChange && (
        <div className="flex justify-end mb-2 gap-1">
          {(['compact', 'default', 'spacious'] as Density[]).map((d) => {
            const Icon = d === 'compact' ? Rows4 : d === 'spacious' ? Rows2 : Rows3;
            return (
              <button
                key={d}
                title={`${d} rows`}
                onClick={() => onDensityChange(d)}
                className={cn(
                  'h-7 w-7 inline-flex items-center justify-center rounded-md border border-default',
                  density === d ? 'bg-elevated text-primary' : 'text-muted hover:bg-elevated',
                )}
              >
                <Icon size={14} />
              </button>
            );
          })}
        </div>
      )}

      <div className="border border-default rounded-md overflow-hidden">
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-default bg-canvas">
              {selectable && (
                <th className={cn('px-2.5 w-8', rowHeight.default)}>
                  <Checkbox
                    checked={allOnPageSelected}
                    onChange={toggleAll}
                    aria-label="Select all rows"
                  />
                </th>
              )}
              {visibleColumns.map((col) => (
                <th
                  key={col.key}
                  className={cn(
                    'px-2.5 text-2xs uppercase tracking-wider text-muted font-medium select-none',
                    rowHeight.default,
                    alignClass[col.align ?? 'left'],
                    col.sortable && onSort && 'cursor-pointer hover:text-primary',
                    col.className,
                  )}
                  onClick={() => handleHeaderClick(col)}
                >
                  <span className="inline-flex items-center">
                    {col.header}
                    {sortIndicator(col)}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.map((row, i) => {
              const rid = idOf(row);
              const isSelected = selected.has(rid);
              return (
                <tr
                  key={rid || i}
                  className={cn(
                    'border-b border-subtle transition-colors duration-fast',
                    onRowClick && 'cursor-pointer hover:bg-subtle',
                    isSelected && 'bg-info-bg/30',
                    rowHeight[density],
                  )}
                  onClick={(e) => {
                    // Skip row click when clicking the checkbox column.
                    if ((e.target as HTMLElement).closest('input,button,a,label')) return;
                    onRowClick?.(row);
                  }}
                >
                  {selectable && (
                    <td className="px-2.5 align-middle">
                      <Checkbox
                        checked={isSelected}
                        onChange={() => toggleOne(rid)}
                        aria-label="Select row"
                      />
                    </td>
                  )}
                  {visibleColumns.map((col) => (
                    <td
                      key={col.key}
                      className={cn('px-2.5 align-middle', alignClass[col.align ?? 'left'], col.className)}
                    >
                      {col.cell(row)}
                    </td>
                  ))}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta && onPageChange && (
        <div className="flex items-center justify-between mt-3">
          <div className="text-xs text-muted font-mono tabular-nums">
            {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
          </div>
          <div className="flex items-center gap-1">
            <Button size="sm" variant="ghost" disabled={meta.current_page <= 1} onClick={() => onPageChange(1)} aria-label="First page" icon={<ChevronsLeft size={14} />} />
            <Button size="sm" variant="ghost" disabled={meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)} aria-label="Previous page" icon={<ChevronLeft size={14} />} />
            <span className="text-xs font-mono tabular-nums px-2 text-muted">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <Button size="sm" variant="ghost" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)} aria-label="Next page" icon={<ChevronRight size={14} />} />
            <Button size="sm" variant="ghost" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.last_page)} aria-label="Last page" icon={<ChevronsRight size={14} />} />
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Cell helpers ──────────────────────────────────────────────

/** Format a number cell with mono + tabular-nums and right-align (use in `align: 'right'`). */
export function NumCell({ children, className }: { children: ReactNode; className?: string }) {
  return <span className={cn('font-mono tabular-nums', className)}>{children}</span>;
}

/** Two-line cell for a primary value + muted subtitle. */
export function StackedCell({ primary, secondary }: { primary: ReactNode; secondary?: ReactNode }) {
  return (
    <div>
      <div className="font-medium leading-tight">{primary}</div>
      {secondary && <div className="text-xs text-muted leading-tight">{secondary}</div>}
    </div>
  );
}
