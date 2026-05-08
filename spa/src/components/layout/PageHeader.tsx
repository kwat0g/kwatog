import { Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { RefreshingIndicator } from './RefreshingIndicator';

interface PageHeaderProps {
  title: ReactNode;
  subtitle?: ReactNode;
  backTo?: string;
  backLabel?: string;
  actions?: ReactNode;
  /** Optional row below the header (e.g. ChainHeader on detail pages). */
  bottom?: ReactNode;
  className?: string;
  /**
   * Series X / Task X5 — when supplied, render a small "Refreshing…" pill
   * next to the title while any matching TanStack Query is refetching in
   * the background. Use the same key shape you pass to `useQuery`.
   */
  refreshingQueryKey?: readonly unknown[];
}

export function PageHeader({
  title,
  subtitle,
  backTo,
  backLabel,
  actions,
  bottom,
  className,
  refreshingQueryKey,
}: PageHeaderProps) {
  return (
    <div className={cn('px-5 py-4 border-b border-default bg-canvas', className)}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          {backTo && (
            <Link to={backTo} className="inline-flex items-center gap-1 text-xs text-muted hover:text-primary mb-1">
              <ArrowLeft size={11} />
              {backLabel ?? 'Back'}
            </Link>
          )}
          <h1 className="text-xl font-medium text-primary truncate">
            {title}
            {refreshingQueryKey && <RefreshingIndicator queryKey={refreshingQueryKey} />}
          </h1>
          {subtitle && <div className="text-xs text-muted mt-0.5">{subtitle}</div>}
        </div>
        {actions && <div className="flex items-center gap-1.5 shrink-0">{actions}</div>}
      </div>
      {bottom && <div className="mt-3">{bottom}</div>}
    </div>
  );
}
