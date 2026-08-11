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
  /**
   * Which way is good. `direction` stays literal — the arrow always points the
   * way the number moved — but on a plant floor most KPIs invert: scrap rate,
   * downtime, defect PPM, overdue bills and mold shot count are all bad when
   * they rise. Defaults to `higher-is-better`, so every existing call site
   * renders exactly as before.
   */
  polarity?: 'higher-is-better' | 'lower-is-better';
}

/** [polarity][direction] → colour. `neutral` is never good or bad. */
const deltaColor = {
  'higher-is-better': {
    up: 'text-success-fg',
    down: 'text-danger-fg',
    neutral: 'text-muted',
  },
  'lower-is-better': {
    up: 'text-danger-fg',
    down: 'text-success-fg',
    neutral: 'text-muted',
  },
} as const;

const deltaArrow = { up: '↑', down: '↓', neutral: '·' } as const;

export function StatCard({
  label,
  value,
  delta,
  helper,
  className,
  linkTo,
  polarity = 'higher-is-better',
}: StatCardProps) {
  const inner = (
    <>
      {/* text-muted, not text-subtle: this string is what the number MEANS, so it
 is essential text at the 4.5:1 bar, not decoration at the 3:1 bar. */}
      <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1.5">{label}</div>
      <div className="text-2xl font-medium font-mono tabular-nums text-primary leading-tight">
        {value}
      </div>
      {delta && (
        <div
          className={cn(
            'text-xs font-mono tabular-nums mt-1',
            deltaColor[polarity][delta.direction],
          )}
        >
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
