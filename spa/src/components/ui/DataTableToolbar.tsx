import { useEffect, useId, useRef, useState } from 'react';
import { LuSettings2 } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { Checkbox } from './Checkbox';
import { SegmentedControl } from './SegmentedControl';
import type { Column } from './DataTable';
import type { TableDensity } from '@/stores/tablePrefsStore';
import { focusRing } from '@/lib/focus';

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

 if (!showVisibilityToggle && !showDensity) return null;

 return (
 <div ref={rootRef} data-layout="table-toolbar" className="relative mb-3 flex flex-wrap items-center justify-end gap-2">
 {showVisibilityToggle && (
 <>
 <button
 ref={columnsButtonRef}
 type="button"
 title="Customize columns"
 aria-label="Customize columns"
 aria-expanded={visMenuOpen}
 aria-controls={menuId}
 onClick={() => setVisMenuOpen((v) => !v)}
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
 className="absolute right-0 top-full z-30 mt-2 w-56 bg-elevated border border-default rounded-md py-2"
 >
 <div className="px-3 pb-2 text-caption text-muted font-medium">
 Columns
 </div>
 {columns
 .filter((c) => c.togglable !== false)
 .map((c) => (
 <label
 key={c.key}
 className="flex min-h-hit items-center gap-2 px-3 text-label cursor-pointer hover:bg-subtle"
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
 </div>
 );
}
