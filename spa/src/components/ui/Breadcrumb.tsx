/**
 * Breadcrumb navigation component.
 *
 * Renders a trail of ancestor links so users can navigate back up from
 * detail pages without finding the parent in the sidebar.
 *
 * Usage:
 * ```tsx
 * <Breadcrumb segments={[
 *   { label: 'Employees', href: '/hr/employees' },
 *   { label: employee.name },
 * ]} />
 * ```
 */
import { Link } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/cn';

export interface BreadcrumbSegment {
  label: string;
  href?: string;
}

interface BreadcrumbProps {
  segments: BreadcrumbSegment[];
  className?: string;
}

export function Breadcrumb({ segments, className }: BreadcrumbProps) {
  if (!segments.length) return null;

  return (
    <nav
      aria-label="Breadcrumb"
      className={cn('flex items-center gap-1 text-xs text-text-subtle mb-3', className)}
    >
      {segments.map((seg, i) => {
        const isLast = i === segments.length - 1;
        return (
          <span key={`${seg.label}-${i}`} className="flex items-center gap-1">
            {i > 0 && <ChevronRight size={12} className="text-text-subtle/50" aria-hidden />}
            {seg.href && !isLast ? (
              <Link to={seg.href} className="hover:text-accent transition-colors truncate max-w-[200px]">
                {seg.label}
              </Link>
            ) : (
              <span className={cn(isLast && 'text-primary font-medium', 'truncate max-w-[200px]')}>
                {seg.label}
              </span>
            )}
          </span>
        );
      })}
    </nav>
  );
}
