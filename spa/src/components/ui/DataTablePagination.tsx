import {
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
} from 'lucide-react';
import type { PaginationMeta } from '@/types';

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
        <button
          type="button"
          disabled={meta.current_page <= 1}
          onClick={() => onPageChange(1)}
          aria-label="First page"
          className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary disabled:opacity-30 disabled:cursor-not-allowed transition-colors duration-fast active:scale-[0.95]"
        >
          <ChevronsLeft size={14} />
        </button>
        <button
          type="button"
          disabled={meta.current_page <= 1}
          onClick={() => onPageChange(meta.current_page - 1)}
          aria-label="Previous page"
          className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary disabled:opacity-30 disabled:cursor-not-allowed transition-colors duration-fast active:scale-[0.95]"
        >
          <ChevronLeft size={14} />
        </button>
        <span className="text-xs font-mono tabular-nums px-2 text-muted">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <button
          type="button"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPageChange(meta.current_page + 1)}
          aria-label="Next page"
          className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary disabled:opacity-30 disabled:cursor-not-allowed transition-colors duration-fast active:scale-[0.95]"
        >
          <ChevronRight size={14} />
        </button>
        <button
          type="button"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPageChange(meta.last_page)}
          aria-label="Last page"
          className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary disabled:opacity-30 disabled:cursor-not-allowed transition-colors duration-fast active:scale-[0.95]"
        >
          <ChevronsRight size={14} />
        </button>
      </div>
    </div>
  );
}
