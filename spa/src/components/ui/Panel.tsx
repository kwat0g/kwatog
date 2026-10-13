import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface PanelProps {
  title?: ReactNode;
  meta?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
  bodyClassName?: string;
  noPadding?: boolean;
  /**
   * Let an over-wide child scroll instead of being clipped.
   *
   * The panel clips its own overflow to keep children inside the rounded
   * corners, which silently cut the right edge off any table narrower than its
   * own min-content width — no scrollbar, no indication content was lost. The
   * finance and PPC dashboards worked around it with an `overflow-x-auto` div;
   * this makes the capability part of the primitive. Opt-in, so nothing that
   * relies on clipping (popovers, absolutely-positioned decoration) changes.
   */
  scrollBody?: boolean;
}

export function Panel({
  title,
  meta,
  actions,
  children,
  className,
  bodyClassName,
  noPadding,
  scrollBody,
}: PanelProps) {
  return (
    <div className={cn('bg-canvas border border-default rounded-md overflow-hidden', className)}>
      {/*
       * `min-w-0` on both the title cluster and the meta slot is what lets a
       * wide `meta` actually use a narrow-width control (F-010's `/budgeting`
       * status filter: 340px of options in a 301px slot). Without it a flex
       * item's automatic minimum size is its content's, so the whole header
       * grew to 411px inside a 333px panel and `overflow-hidden` cut the
       * options off — unreachable, with no scrollbar to say so. Inert
       * whenever the header's content fits, which is every other call site.
       */}
      {(title || meta || actions) && (
        <div className="flex items-center justify-between px-4 py-3 border-b border-default">
          <div className="flex min-w-0 items-baseline gap-2">
            {title && <h3 className="text-sm font-medium text-primary">{title}</h3>}
            {meta && <span className="min-w-0 text-xs text-muted">{meta}</span>}
          </div>
          {actions && <div className="flex items-center gap-1.5">{actions}</div>}
        </div>
      )}
      <div className={cn(noPadding ? '' : 'p-4', scrollBody && 'overflow-x-auto', bodyClassName)}>
        {children}
      </div>
    </div>
  );
}
