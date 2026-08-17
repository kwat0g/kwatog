import {
 LuChevronLeft,
 LuChevronRight,
 LuChevronsLeft,
 LuChevronsRight,
} from '@/lib/icons';
import type { PaginationMeta } from '@/types';
import { cn } from '@/lib/cn';
import { Button } from './Button';

interface PaginationProps {
 meta: PaginationMeta;
 onPageChange: (page: number) => void;
 /**
  * Supply to render a rows-per-page control. Omitted on tables whose page size
  * is fixed by the endpoint. Without this there was no way to see more than a
  * page of a long list except by paging — painful on the dense tables this
  * design system exists to serve.
  */
 onPageSizeChange?: (perPage: number) => void;
 /** Current rows per page; required for the control to reflect reality. */
 perPage?: number;
}

/** Sized for dense office tables; 100 is the practical ceiling before the DOM hurts. */
const PAGE_SIZES = [25, 50, 100] as const;

export function DataTablePagination({
 meta,
 onPageChange,
 onPageSizeChange,
 perPage,
}: PaginationProps) {
 return (
 <div className="flex flex-wrap items-center justify-between gap-2 mt-3">
 <div className="flex items-center gap-3">
 <div className="text-xs text-muted font-mono tabular-nums">
 {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
 </div>
 {onPageSizeChange && (
 <label className="flex items-center gap-1.5 text-xs text-muted">
 Rows
 <select
 value={perPage ?? meta.per_page}
 onChange={(e) => onPageSizeChange(Number(e.target.value))}
 className={cn(
 'h-6 rounded-md border border-default bg-canvas px-1.5 text-xs font-mono tabular-nums text-primary',
 'outline-none transition-colors duration-fast focus:border-accent focus:ring-2 focus:ring-accent cursor-pointer',
 )}
 >
 {PAGE_SIZES.map((size) => (
 <option key={size} value={size}>{size}</option>
 ))}
 </select>
 </label>
 )}
 </div>
 <div className="flex items-center gap-1">
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuChevronsLeft size={14} />}
 aria-label="First page"
 disabled={meta.current_page <= 1}
 onClick={() => onPageChange(1)}
 className="text-muted hover:text-primary"
 />
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuChevronLeft size={14} />}
 aria-label="Previous page"
 disabled={meta.current_page <= 1}
 onClick={() => onPageChange(meta.current_page - 1)}
 className="text-muted hover:text-primary"
 />
 <span className="text-xs font-mono tabular-nums px-2 text-muted">
 Page {meta.current_page} of {meta.last_page}
 </span>
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuChevronRight size={14} />}
 aria-label="Next page"
 disabled={meta.current_page >= meta.last_page}
 onClick={() => onPageChange(meta.current_page + 1)}
 className="text-muted hover:text-primary"
 />
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuChevronsRight size={14} />}
 aria-label="Last page"
 disabled={meta.current_page >= meta.last_page}
 onClick={() => onPageChange(meta.last_page)}
 className="text-muted hover:text-primary"
 />
 </div>
 </div>
 );
}
