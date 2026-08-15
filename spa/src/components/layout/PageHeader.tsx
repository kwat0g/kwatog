import { useNavigate } from 'react-router-dom';
import { LuArrowLeft } from '@/lib/icons';
import { isValidElement, useEffect, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { RefreshingIndicator } from './RefreshingIndicator';
import { Breadcrumb, type BreadcrumbSegment } from '@/components/ui/Breadcrumb';

interface PageHeaderProps {
  title: ReactNode;
  subtitle?: ReactNode;
  backTo?: string;
  backLabel?: string;
  actions?: ReactNode;
  /** Optional row below the header (e.g. ChainHeader on detail pages). */
  bottom?: ReactNode;
  className?: string;
  /** Breadcrumb trail below the back link, above the title. */
  breadcrumbs?: BreadcrumbSegment[];
  /**
   * Series X / Task X5 — when supplied, render a small "Refreshing…" pill
   * next to the title while any matching TanStack Query is refetching in
   * the background. Use the same key shape you pass to `useQuery`.
   */
  refreshingQueryKey?: readonly unknown[];
}

function textFromNode(node: ReactNode): string {
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(textFromNode).join(' ');
  if (isValidElement(node)) {
    return textFromNode((node.props as { children?: ReactNode }).children);
  }
  return '';
}

export function PageHeader({
  title,
  subtitle,
  breadcrumbs,
  backTo,
  backLabel,
  actions,
  bottom,
  className,
  refreshingQueryKey,
}: PageHeaderProps) {
  const navigate = useNavigate();
  const documentTitle = textFromNode(title).replace(/\s+/g, ' ').trim();

  useEffect(() => {
    if (documentTitle) {
      document.title = `${documentTitle} · ERP`;
    }
  }, [documentTitle]);

  const handleBackClick = (e: React.MouseEvent) => {
    e.preventDefault();
    // The label states where the link goes ("Back to Employees"), so it has to
    // go there. This preferred `navigate(-1)` whenever history had depth, which
    // meant arriving from a dashboard drill-down or a command-palette jump sent
    // the user somewhere the label never mentioned. `backTo` wins when supplied;
    // history is only the fallback for headers that declare no destination.
    if (backTo) {
      navigate(backTo);
    } else if (window.history.state && window.history.state.idx > 0) {
      navigate(-1);
    }
  };

  return (
    <div className={cn('px-5 py-4 border-b border-default bg-canvas', className)}>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          {breadcrumbs && breadcrumbs.length > 0 && (
            <Breadcrumb segments={breadcrumbs} className="mb-2" />
          )}
          {backTo && (
            <a href={backTo} onClick={handleBackClick} className="inline-flex items-center gap-1 text-xs text-muted hover:text-primary mb-1">
              <LuArrowLeft size={11} />
              {backLabel ?? 'Back'}
            </a>
          )}
 <h1 className="font-display text-2xl text-primary truncate">
            {title}
            {refreshingQueryKey && <RefreshingIndicator queryKey={refreshingQueryKey} />}
          </h1>
          {subtitle && <div className="text-xs text-muted mt-0.5">{subtitle}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-1.5 sm:shrink-0">{actions}</div>}
      </div>
      {bottom && <div className="mt-3">{bottom}</div>}
    </div>
  );
}
