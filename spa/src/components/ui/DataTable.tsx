import {
  Fragment,
  useCallback,
  useEffect,
  useRef,
  useMemo,
  useState,
  type ReactNode,
  type KeyboardEvent,
} from 'react';
import { LuArrowDown, LuArrowUp, LuChevronDown } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { focusRingInset } from '@/lib/focus';
import { Button } from './Button';
import { Checkbox } from './Checkbox';
import { RowContextMenu, type RowContextMenuItem } from './RowContextMenu';
import { useTablePrefsStore, type TableDensity } from '@/stores/tablePrefsStore';
import { DataTableToolbar } from './DataTableToolbar';
import { DataTablePagination } from './DataTablePagination';
import { EmptyState } from './EmptyState';
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
  /** Hide by default; user can show via "Customize columns". */
  defaultHidden?: boolean;
  /** Series X / Task X4 — pin column to the left edge so it stays visible on horizontal scroll. */
  pinned?: 'left';
  /** Series X / Task X4 — when set, column appears in the visibility toggle UI. Default true. */
  togglable?: boolean;
}

// Re-export for backwards compatibility — tests / pages import this name.
export type Density = TableDensity;

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
  /** Supply to render a rows-per-page control in the pagination footer. */
  onPageSizeChange?: (perPage: number) => void;
  onSort?: (sort: string, direction: 'asc' | 'desc') => void;
  currentSort?: string;
  currentDirection?: 'asc' | 'desc';
  onRowClick?: (row: T) => void;
  selectable?: boolean;
  bulkActions?: BulkAction<T>[];
  density?: TableDensity;
  onDensityChange?: (density: TableDensity) => void;
  /** Stable identifier per row for selection state. Defaults to `(row as any).id`. */
  getRowId?: (row: T) => string;
  /** Persistently highlight a single row (used for list + detail-panel layouts). */
  highlightedRowId?: string | null;
  className?: string;

  // ─── Series X / Task X4 additions ────────────────────────────

  /**
   * Stable key for persisting per-user preferences (density, hidden columns).
   * When omitted, prefs are session-only via local component state.
   */
  tableKey?: string;
  /** Renders an inline expandable detail row when set. The chevron column appears at the start. */
  renderExpanded?: (row: T) => ReactNode;
  /** Right-click context menu items. Receives the row that was clicked. */
  rowContextMenu?: (row: T) => RowContextMenuItem[];
  /** Sticky `<thead>` while vertically scrolling the table container. Default true. */
  stickyHeader?: boolean;
  /**
   * Render a "Customize columns" button next to the density toggle so the user
   * can hide columns marked `togglable !== false`. Default true when `tableKey`
   * is set.
   */
  enableColumnVisibilityToggle?: boolean;
  /**
   * Rendered inside the tbody when `data` is empty. Defaults to a compact
   * EmptyState so an empty table matches every other empty surface rather than
   * a bare grey sentence. Pass a node to tailor the copy (active filters, etc.).
   */
  emptyState?: ReactNode;
}

// ─── Density mapping ───────────────────────────────────────────

const rowHeight: Record<TableDensity, string> = {
  compact: 'h-7',
  // `h-row` is `--row-height`: 32px in the office palettes, 48px on the shop
  // floor. compact/spacious stay fixed — they are explicit operator choices,
  // and only the default should follow the palette's density.
  default: 'h-row',
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
  onPageSizeChange,
  onSort,
  currentSort,
  currentDirection,
  onRowClick,
  selectable,
  bulkActions,
  density: densityProp,
  onDensityChange,
  getRowId,
  highlightedRowId,
  className,
  tableKey,
  renderExpanded,
  rowContextMenu,
  stickyHeader = true,
  enableColumnVisibilityToggle,
  emptyState,
}: DataTableProps<T>) {
  // ─── Selection state ──────────────────────────────────────
  const [selected, setSelected] = useState<Set<string>>(new Set());

  // ─── Expanded rows ────────────────────────────────────────
  const [expanded, setExpanded] = useState<Set<string>>(new Set());

  // ─── Context menu ─────────────────────────────────────────
  const [ctxMenu, setCtxMenu] = useState<{
    x: number;
    y: number;
    items: RowContextMenuItem[];
  } | null>(null);

  // ─── Persistent prefs ─────────────────────────────────────
  const prefs = useTablePrefsStore((s) => (tableKey ? s.byTable[tableKey] : undefined));
  const setStoreDensity = useTablePrefsStore((s) => s.setDensity);
  const setStoreHidden = useTablePrefsStore((s) => s.setHiddenColumns);
  const density: TableDensity = densityProp ?? prefs?.density ?? 'default';
  const hiddenColumnKeys = useMemo(
    () => new Set(prefs?.hiddenColumns ?? columns.filter((c) => c.defaultHidden).map((c) => c.key)),
    [prefs?.hiddenColumns, columns],
  );

  // ─── Column visibility is handled inside DataTableToolbar ──

  const handleDensityChange = (d: TableDensity) => {
    onDensityChange?.(d);
    if (tableKey) setStoreDensity(tableKey, d);
  };

  const toggleColumnVisibility = (colKey: string) => {
    const next = new Set(hiddenColumnKeys);
    if (next.has(colKey)) next.delete(colKey);
    else next.add(colKey);
    if (tableKey) setStoreHidden(tableKey, Array.from(next));
  };

  const idOf = useCallback(
    (row: T, index?: number) => {
      if (getRowId) return getRowId(row);
      const r = row as { id?: unknown; slug?: unknown; key?: unknown; code?: unknown };
      const val = r.id ?? r.slug ?? r.key ?? r.code;
      if (val !== undefined && val !== null && String(val) !== '') return String(val);
      return index !== undefined ? `row-${index}` : '';
    },
    [getRowId],
  );

  // ─── Visible / pinned columns ─────────────────────────────
  const visibleColumns = useMemo(
    () => columns.filter((c) => !hiddenColumnKeys.has(c.key)),
    [columns, hiddenColumnKeys],
  );
  const pinnedColumns = visibleColumns.filter((c) => c.pinned === 'left');
  const flowingColumns = visibleColumns.filter((c) => !c.pinned);
  // Render pinned first, flowing after — same visual order as the array we
  // build here. Sticky positioning takes care of the "stay-on-screen" effect.
  const orderedColumns = [...pinnedColumns, ...flowingColumns];

  // Approximate widths (px) for sticky offsets. Real widths depend on content;
  // we use a conservative estimate so adjacent pinned columns offset cleanly
  // without overlapping. Pages can override per column with `className` (e.g.
  // `min-w-[160px]`) to make this exact.
  const PINNED_COL_DEFAULT_WIDTH = 160;
  const pinnedOffsets = useMemo(() => {
    const offsets: Record<string, number> = {};
    let cumulative = selectable ? 32 : 0; // checkbox column
    if (renderExpanded) cumulative += 28; // expand chevron
    for (const col of pinnedColumns) {
      offsets[col.key] = cumulative;
      cumulative += PINNED_COL_DEFAULT_WIDTH;
    }
    return offsets;
  }, [pinnedColumns, selectable, renderExpanded]);

  const allOnPageSelected = data.length > 0 && data.every((r) => selected.has(idOf(r)));
  const selectedRows = data.filter((r) => selected.has(idOf(r)));

  // Selection is scoped to what is on screen, because that is all a bulk action
  // can actually receive: `selectedRows` filters `data`, so ids retained from a
  // previous page were counted by nothing and acted on by nothing. Keeping them
  // was worse than dropping them — a user who ticked 10 rows on page 1, paged
  // to 2, ticked 10 more and hit Approve approved only the visible 10, with a
  // badge that agreed. Reset when the visible set changes so the count on
  // screen is always the count that will be submitted.
  const visibleIds = data.map((r, i) => idOf(r, i)).join(' ');
  const lastVisibleIds = useRef(visibleIds);
  useEffect(() => {
    if (lastVisibleIds.current === visibleIds) return;
    lastVisibleIds.current = visibleIds;
    setSelected((prev) => (prev.size === 0 ? prev : new Set()));
  }, [visibleIds]);

  const toggleAll = () => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (allOnPageSelected) data.forEach((r) => next.delete(idOf(r)));
      else data.forEach((r) => next.add(idOf(r)));
      return next;
    });
  };

  const toggleOne = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleExpanded = (id: string) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const sortIndicator = (col: Column<T>) => {
    if (!col.sortable) return null;
    const active = currentSort === col.key;
    // An unsorted column used to render the same down-arrow at 30% opacity,
    // which reads as "sorted descending" rather than "sortable". Show the
    // neutral up/down pair until the column is actually the sort key.
    if (!active) {
      return (
        <span className="inline-flex flex-col leading-none ml-1 opacity-40" aria-hidden="true">
          <LuArrowUp size={8} />
          <LuArrowDown size={8} />
        </span>
      );
    }
    return (
      <span className="inline-block ml-1" aria-hidden="true">
        {currentDirection === 'asc' ? <LuArrowUp size={10} /> : <LuArrowDown size={10} />}
      </span>
    );
  };

  /** `aria-sort` is the only way a screen reader learns a column is ordered. */
  const ariaSortFor = (col: Column<T>): 'ascending' | 'descending' | 'none' | undefined => {
    if (!col.sortable || !onSort) return undefined;
    if (currentSort !== col.key) return 'none';
    return currentDirection === 'asc' ? 'ascending' : 'descending';
  };

  const handleHeaderClick = (col: Column<T>) => {
    if (!col.sortable || !onSort) return;
    const nextDir: 'asc' | 'desc' =
      currentSort === col.key && currentDirection === 'asc' ? 'desc' : 'asc';
    onSort(col.key, nextDir);
  };

  // Pinned-column sticky styles. We embed inline styles for `left:` because
  // Tailwind can't dynamically compute the cumulative offset.
  const pinnedTHClass = 'sticky bg-canvas z-20';
  const pinnedTDClass = 'sticky bg-canvas';

  const totalCols = visibleColumns.length + (selectable ? 1 : 0) + (renderExpanded ? 1 : 0);

  const tbodyRef = useRef<HTMLTableSectionElement>(null);

  const onRowKeyDown = useCallback(
    (e: KeyboardEvent<HTMLTableSectionElement>) => {
      const target = e.target as HTMLElement;
      if (target.tagName !== 'TR') return;

      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const rows = tbodyRef.current?.querySelectorAll<HTMLElement>('tr[tabindex]');
        if (!rows) return;
        const arr = Array.from(rows);
        const idx = arr.indexOf(target);
        const next = e.key === 'ArrowDown' ? idx + 1 : idx - 1;
        if (next >= 0 && next < arr.length) arr[next].focus();
      }

      if (e.key === ' ' && selectable) {
        e.preventDefault();
        const checkbox = target.querySelector<HTMLInputElement>('input[type="checkbox"]');
        if (checkbox) checkbox.click();
      }

      if (e.key === 'Escape' && selected.size > 0) {
        e.preventDefault();
        setSelected(new Set());
      }

      if (e.key === 'Enter' && onRowClick) {
        e.preventDefault();
        target.click();
      }
    },
    [onRowClick, selectable, selected.size],
  );

  return (
    <div className={cn('flex flex-col', className)}>
      {/* Screen reader announcement for result count changes */}
      <div className="sr-only" aria-live="polite" aria-atomic="true">
        {meta
          ? `Showing ${data.length} of ${meta.total} results, page ${meta.current_page}`
          : `${data.length} ${data.length === 1 ? 'result' : 'results'}`}
      </div>

      {/* Bulk action bar */}
      {selectable && selectedRows.length > 0 && (
        <div className="flex items-center justify-between px-3 py-2 bg-info-bg border border-default rounded-md mb-2">
          <div className="text-xs text-primary">
            <span className="font-mono tabular-nums">{selectedRows.length}</span> selected
          </div>
          <div className="flex items-center gap-1.5">
            {bulkActions?.map((a, i) => (
              <Button
                key={i}
                size="sm"
                variant={a.variant ?? 'secondary'}
                icon={a.icon}
                onClick={() => a.onClick(selectedRows)}
              >
                {a.label}
              </Button>
            ))}
            <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
              Clear
            </Button>
          </div>
        </div>
      )}

      <DataTableToolbar<T>
        columns={columns}
        density={density}
        onDensityChange={handleDensityChange}
        tableKey={tableKey}
        enableColumnVisibilityToggle={enableColumnVisibilityToggle}
        hiddenColumnKeys={hiddenColumnKeys}
        toggleColumnVisibility={toggleColumnVisibility}
      />

      <div className="overflow-x-auto rounded-md border border-default">
        <table className="w-full border-collapse text-xs">
          <thead className={cn(stickyHeader && 'sticky top-0 z-10 bg-[var(--bg-thead)]')}>
            <tr className="border-b border-default bg-[var(--bg-thead)]">
              {selectable && (
                <th
                  scope="col"
                  className={cn(
                    'px-2.5 w-8 text-left text-2xs uppercase tracking-wider text-muted font-medium',
                    rowHeight.default,
                    stickyHeader && 'sticky top-0 z-20',
                  )}
                >
                  <Checkbox
                    checked={allOnPageSelected}
                    onChange={toggleAll}
                    aria-label="Select all rows"
                  />
                </th>
              )}
              {renderExpanded && (
                <th
                  scope="col"
                  className={cn(
                    'px-1.5 w-7 text-left text-2xs uppercase tracking-wider text-muted font-medium',
                    rowHeight.default,
                    stickyHeader && 'sticky top-0 z-20',
                  )}
                  aria-label="Expand row"
                />
              )}
              {orderedColumns.map((col) => {
                const isPinned = col.pinned === 'left';
                const isSortable = Boolean(col.sortable && onSort);
                return (
                  <th
                    key={col.key}
                    scope="col"
                    aria-sort={ariaSortFor(col)}
                    style={isPinned ? { left: pinnedOffsets[col.key] } : undefined}
                    className={cn(
                      'px-2.5 text-2xs uppercase tracking-wider text-muted font-medium select-none',
                      rowHeight.default,
                      alignClass[col.align ?? 'left'],
                      isPinned && pinnedTHClass,
                      isPinned && 'border-r border-default',
                      col.className,
                    )}
                  >
                    {/* Sorting used to live on the <th>'s onClick, so it was
                        mouse-only — DESIGN-SYSTEM.md requires every
                        interactive element be keyboard reachable. A real
                        <button> gets Enter/Space and the focus ring free. */}
                    {isSortable ? (
                      <button
                        type="button"
                        onClick={() => handleHeaderClick(col)}
                        className={cn(
                          'inline-flex items-center gap-1 cursor-pointer rounded-sm',
                          'hover:text-primary transition-colors duration-fast',
                          focusRingInset,
                        )}
                      >
                        {col.header}
                        {sortIndicator(col)}
                      </button>
                    ) : (
                      <span className="inline-flex items-center gap-1">{col.header}</span>
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody ref={tbodyRef} onKeyDown={onRowKeyDown}>
            {data.length === 0 ? (
              <tr>
                <td colSpan={totalCols} className="bg-canvas p-0">
                  {emptyState ?? (
                    <EmptyState
                      size="compact"
                      icon="search-x"
                      title="No matching records"
                      description="Try adjusting your search or filters."
                    />
                  )}
                </td>
              </tr>
            ) : (
              data.map((row, i) => {
                const rid = idOf(row, i);
                const isSelected = rid ? selected.has(rid) : false;
                const isExpanded = rid ? expanded.has(rid) : false;
                return (
                  <Fragment key={rid}>
                    <tr
                      tabIndex={onRowClick || selectable ? 0 : undefined}
                      className={cn(
                        'border-b border-subtle transition-colors duration-fast relative group',
                        (onRowClick || selectable) && 'cursor-pointer active:scale-[0.998]',
                        (onRowClick || selectable) &&
                          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset',
                        isSelected
                          ? 'bg-accent/10 hover:bg-accent/15 outline outline-2 outline-accent -outline-offset-2 z-10'
                          : highlightedRowId && rid === highlightedRowId
                            ? 'bg-subtle outline outline-2 outline-accent/50 -outline-offset-2 z-10'
                            : 'hover:bg-surface hover:z-10',
                        rowHeight[density],
                      )}
                      onClick={(e) => {
                        // Skip row click when clicking interactive elements.
                        if ((e.target as HTMLElement).closest('input,button,a,label')) return;
                        // Skip row click if the user is currently highlighting text.
                        if (window.getSelection()?.toString().length) return;
                        onRowClick?.(row);
                      }}
                      onContextMenu={
                        rowContextMenu
                          ? (e) => {
                              const items = rowContextMenu(row);
                              if (items.length === 0) return;
                              e.preventDefault();
                              setCtxMenu({ x: e.clientX, y: e.clientY, items });
                            }
                          : undefined
                      }
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
                      {renderExpanded && (
                        <td className="px-1.5 w-7 align-middle">
                          <button
                            type="button"
                            aria-label={isExpanded ? 'Collapse row' : 'Expand row'}
                            onClick={() => toggleExpanded(rid)}
                            className={cn(
                              'h-5 w-5 inline-flex items-center justify-center rounded text-muted hover:bg-subtle hover:text-primary cursor-pointer',
                              focusRingInset,
                            )}
                          >
                            <LuChevronDown
                              size={12}
                              className={cn(
                                'transition-transform duration-fast',
                                isExpanded && 'rotate-180',
                              )}
                            />
                          </button>
                        </td>
                      )}
                      {orderedColumns.map((col) => {
                        const isPinned = col.pinned === 'left';
                        return (
                          <td
                            key={col.key}
                            style={isPinned ? { left: pinnedOffsets[col.key] } : undefined}
                            className={cn(
                              'px-2.5 align-middle',
                              alignClass[col.align ?? 'left'],
                              isPinned && pinnedTDClass,
                              isPinned && 'border-r border-default',
                              col.className,
                            )}
                          >
                            {col.cell(row)}
                          </td>
                        );
                      })}
                    </tr>
                    {renderExpanded && isExpanded && (
                      <tr className="bg-subtle border-b border-subtle">
                        <td colSpan={totalCols} className="px-4 py-3">
                          {renderExpanded(row)}
                        </td>
                      </tr>
                    )}
                  </Fragment>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {meta && onPageChange && (
        <DataTablePagination
          meta={meta}
          onPageChange={onPageChange}
          onPageSizeChange={onPageSizeChange}
          perPage={meta.per_page}
        />
      )}

      <RowContextMenu
        open={ctxMenu !== null}
        x={ctxMenu?.x ?? 0}
        y={ctxMenu?.y ?? 0}
        items={ctxMenu?.items ?? []}
        onClose={() => setCtxMenu(null)}
      />
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
