import { useEffect, useId, useRef, useState } from 'react';
import { LuSettings2 } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import { Checkbox } from './Checkbox';
import { SegmentedControl } from './SegmentedControl';
import { Toolbar } from './Toolbar';
import type { Column } from './DataTable';
import type { TableDensity } from '@/stores/tablePrefsStore';

interface ToolbarProps<T> {
  columns: Column<T>[];
  density: TableDensity;
  onDensityChange?: (density: TableDensity) => void;
  tableKey?: string;
  enableColumnVisibilityToggle?: boolean;
  hiddenColumnKeys: Set<string>;
  toggleColumnVisibility: (key: string) => void;
  hasCustomWidths: boolean;
  resetColumnWidths: () => void;
}

export function DataTableToolbar<T>({
  columns,
  density,
  onDensityChange,
  tableKey,
  enableColumnVisibilityToggle,
  hiddenColumnKeys,
  toggleColumnVisibility,
  hasCustomWidths,
  resetColumnWidths,
}: ToolbarProps<T>) {
  const [visMenuOpen, setVisMenuOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const columnsButtonRef = useRef<HTMLButtonElement>(null);
  const menuId = useId();
  const showVisibilityToggle = enableColumnVisibilityToggle ?? !!tableKey;
  const showDensity = !!onDensityChange || !!tableKey;

  useEffect(() => {
    if (!visMenuOpen) return;
    const closeOnOutside = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setVisMenuOpen(false);
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      event.stopPropagation();
      setVisMenuOpen(false);
      columnsButtonRef.current?.focus();
    };
    document.addEventListener('pointerdown', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [visMenuOpen]);

  if (!showVisibilityToggle && !showDensity && !hasCustomWidths) return null;

  return (
    <div ref={rootRef}>
      <Toolbar
        layout="table-toolbar"
        className="relative mb-4 justify-end"
        end={(
          <>
            {showVisibilityToggle && (
              <div className="relative">
                <button
                  ref={columnsButtonRef}
                  type="button"
                  title="Customize columns"
                  aria-label="Customize columns"
                  aria-expanded={visMenuOpen}
                  aria-controls={menuId}
                  onClick={() => setVisMenuOpen((open) => !open)}
                  className={cn('inline-flex min-h-hit items-center justify-center gap-2 rounded-md border border-default px-3 text-label text-primary hover:bg-elevated transition-colors duration-fast cursor-pointer', focusRing)}
                >
                  <LuSettings2 size={14} />
                  <span>Columns</span>
                </button>
                {visMenuOpen && (
                  <div
                    id={menuId}
                    role="group"
                    aria-label="Choose visible columns"
                    onMouseLeave={() => setVisMenuOpen(false)}
                    className="absolute right-0 top-full z-30 mt-2 w-56 bg-elevated border border-default rounded-md py-2"
                  >
                    <div className="px-3 pb-2 text-caption text-muted font-medium">Columns</div>
                    {columns
                      .filter((column) => column.togglable !== false)
                      .map((column) => (
                        <label key={column.key} className="flex min-h-hit items-center gap-2 px-3 text-label cursor-pointer hover:bg-subtle">
                          <Checkbox
                            checked={!hiddenColumnKeys.has(column.key)}
                            onChange={() => toggleColumnVisibility(column.key)}
                          />
                          <span className="text-primary">
                            {typeof column.header === 'string' ? column.header : column.key}
                          </span>
                        </label>
                      ))}
                  </div>
                )}
              </div>
            )}
            {showDensity && (
              <SegmentedControl<TableDensity>
                options={[
                  { value: 'compact', label: 'Compact' },
                  { value: 'default', label: 'Default' },
                  { value: 'spacious', label: 'Spacious' },
                ]}
                value={density}
                onChange={(value) => onDensityChange?.(value as TableDensity)}
                label="Table density"
                size="adaptive"
              />
            )}
            {hasCustomWidths && (
              <button
                type="button"
                onClick={resetColumnWidths}
                className={cn('inline-flex min-h-hit items-center rounded-md px-2 text-label text-secondary hover:bg-subtle hover:text-primary transition-colors duration-fast', focusRing)}
              >
                Reset widths
              </button>
            )}
          </>
        )}
      />
    </div>
  );
}
