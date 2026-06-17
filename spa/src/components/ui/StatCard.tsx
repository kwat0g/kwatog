import { type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/cn';

interface StatCardProps {
  label: string;
  value: ReactNode;
  delta?: { value: string; direction: 'up' | 'down' | 'neutral' };
  helper?: string;
  className?: string;
  /**
   * Sprint P8 — when set, the entire card becomes a `<Link>` to the given
   * URL. Card gets cursor-pointer + hover bg-elevated. URL must already
   * include any query params required for filter drill-down (build via
   * `lib/dashboardLinks.ts`).
   */
  linkTo?: string;
}

const deltaColor = {
  up: 'text-success',
  down: 'text-danger',
  neutral: 'text-muted',
} as const;

const deltaArrow = { up: '↑', down: '↓', neutral: '·' } as const;

export function StatCard({ label, value, delta, helper, className, linkTo }: StatCardProps) {
  const inner = (
    <>
      <div className="text-2xs uppercase tracking-wider text-text-subtle font-display font-medium mb-1.5">
        {label}
      </div>
      <div className="text-2xl font-medium font-mono tabular-nums text-primary leading-tight">
        {value}
      </div>
      {delta && (
        <div className={cn('text-xs font-mono tabular-nums mt-1', deltaColor[delta.direction])}>
          {deltaArrow[delta.direction]} {delta.value}
        </div>
      )}
      {!delta && helper && <div className="text-xs text-muted mt-1">{helper}</div>}
    </>
  );

  const baseClass = 'p-3.5 bg-surface border border-default rounded-md';

  if (linkTo) {
    return (
      <Link
        to={linkTo}
        className={cn(
          baseClass,
          'block cursor-pointer hover:bg-elevated transition-colors duration-fast',
          className,
        )}
      >
        {inner}
      </Link>
    );
  }

  return <div className={cn(baseClass, className)}>{inner}</div>;
}
