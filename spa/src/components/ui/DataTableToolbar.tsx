import { useState } from 'react';
import { LuSettings2 } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { Checkbox } from './Checkbox';
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
 const showVisibilityToggle = enableColumnVisibilityToggle ?? !!tableKey;
 const showDensity = !!onDensityChange || !!tableKey;

 if (!showVisibilityToggle && !showDensity) return null;

 return (
 <div data-layout="table-toolbar" className="relative mb-4 flex flex-wrap items-center justify-end gap-2">
 {showVisibilityToggle && (
 <>
 <button
 type="button"
 title="Customize columns"
 aria-label="Customize columns"
 onClick={() => setVisMenuOpen((v) => !v)}
 className={cn('inline-flex h-9 items-center justify-center gap-2 rounded-md border border-default px-3 text-label text-primary hover:bg-elevated transition-colors duration-fast cursor-pointer', focusRing)}
 >
 <LuSettings2 size={14} />
 <span>Columns</span>
 </button>
 {visMenuOpen && (
 <div
 className="absolute right-0 top-10 z-30 w-56 bg-elevated border border-default rounded-md py-2"
 onMouseLeave={() => setVisMenuOpen(false)}
 >
 <div className="px-3 pb-2 text-caption text-muted font-medium">
 Columns
 </div>
 {columns
 .filter((c) => c.togglable !== false)
 .map((c) => (
 <label
 key={c.key}
 className="flex min-h-9 items-center gap-2 px-3 text-label cursor-pointer hover:bg-subtle"
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
 <label className="flex items-center gap-2 text-label text-muted">
 <span>Density</span>
 <select
  value={density}
  aria-label="Density"
  onChange={(event) => onDensityChange?.(event.target.value as TableDensity)}
  className={cn('h-9 rounded-md border border-default bg-canvas px-3 text-label text-primary cursor-pointer', focusRing)}
 >
  <option value="default">Comfortable</option>
  <option value="compact">Compact</option>
  <option value="spacious">Spacious</option>
 </select>
 </label>
 )}
 </div>
 );
}
