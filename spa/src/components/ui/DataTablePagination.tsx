import {
 LuChevronLeft,
 LuChevronRight,
 LuChevronsLeft,
 LuChevronsRight,
} from '@/lib/icons';
import type { PaginationMeta } from '@/types';
import { Button } from './Button';

interface PaginationProps {
 meta: PaginationMeta;
 onPageChange: (page: number) => void;
}

export function DataTablePagination({ meta, onPageChange }: PaginationProps) {
 return (
 <div className="flex items-center justify-between mt-3">
 <div className="text-xs text-muted font-mono tabular-nums">
 {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
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
