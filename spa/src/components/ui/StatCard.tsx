import { type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/cn';
import { LuArrowDown, LuArrowUp } from '@/lib/icons';

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

const deltaText = { up: 'Increase', down: 'Decrease', neutral: 'No change' } as const;

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
      <div className="text-caption text-muted font-medium mb-1.5">{label}</div>
      {/*
       * The figure must never paint outside its own box (F-009). A fixed
       * 26px (`text-2xl`) value is the spec, but the card is one track of a
       * grid the caller chooses — at 375px a two-up grid leaves 132px of
       * content, and `₱576,912.00` measures 175px there, so the whole
       * document became draggable sideways.
       *
       * The wrapper is the measuring container (`container-type: inline-size`),
       * so the step follows the CARD, not the viewport — correct in every grid
       * the card is used in, unlike a `sm:`/`lg:` ladder. 12cqw maps onto the
       * scale the design system already declares (`text-lg` 16px … `text-2xl`
       * 26px, the KPI value); nothing else in the card changed.
       *
       * `overflow-wrap: anywhere` is the backstop, not the mechanism: a value
       * too long for even the 16px floor wraps inside the card instead of
       * dragging the page. Every digit stays on screen — a truncated or
       * clipped figure is worse than a two-line one.
       */}
      <div className="@container">
        <div className="text-[clamp(1rem,12cqw,1.625rem)] font-medium font-sans tabular-nums text-primary leading-tight [overflow-wrap:anywhere]">
          {value}
        </div>
      </div>
      {delta && (
        <div
          className={cn(
            'text-caption font-sans tabular-nums mt-1',
            deltaColor[polarity][delta.direction],
          )}
        >
          <span className="sr-only">{deltaText[delta.direction]} </span>
          <span className="inline-flex items-center gap-1">
            {delta.direction === 'up' ? (
              <LuArrowUp size={12} aria-hidden />
            ) : delta.direction === 'down' ? (
              <LuArrowDown size={12} aria-hidden />
            ) : (
              <span aria-hidden>·</span>
            )}
            {delta.value}
          </span>
        </div>
      )}
      {!delta && helper && <div className="text-caption text-muted mt-1">{helper}</div>}
    </>
  );

  const baseClass = 'p-4 bg-surface border border-default rounded-md';

  if (linkTo) {
    return (
      <Link
        to={linkTo}
        className={cn(
          baseClass,
          'block cursor-pointer transition-colors duration-fast hover:bg-elevated active:scale-[0.998] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
          className,
        )}
      >
        {inner}
      </Link>
    );
  }

  return <div className={cn(baseClass, className)}>{inner}</div>;
}
