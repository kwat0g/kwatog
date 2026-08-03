import { cn } from '@/lib/cn';

export interface ProgressBarProps {
  /** 0–100. Clamped, so over-100 from rounding is safe to pass. */
  value: number;
  /** Status color of the fill (accent = indigo default). */
  variant?: 'accent' | 'success' | 'info' | 'warning' | 'danger';
  /** Height class. Default 1 = the design system's 4px bars. */
  height?: '1' | '2';
  className?: string;
  /**
   * Hidden by default — the design system's 4px bars are decorative. Set when
   * the bar is the only progress cue (e.g. standalone) so screen readers hear
   * it too.
   */
  ariaLabel?: string;
}

const variantFill = {
  accent: 'bg-accent',
  success: 'bg-success',
  info: 'bg-info',
  warning: 'bg-warning',
  danger: 'bg-danger',
} as const;

const heights = { 1: 'h-1', 2: 'h-2' } as const;

/**
 * Design-system progress bar: 4px tall by default, `bg-subtle` track, colored
 * fill with a smooth width transition (the one progress animation the design
 * system permits).
 */
export function ProgressBar({
  value,
  variant = 'accent',
  height = '1',
  className,
  ariaLabel,
}: ProgressBarProps) {
  const pct = Math.min(100, Math.max(0, Math.round(value)));

  return (
    <div
      role={ariaLabel ? 'progressbar' : undefined}
      aria-valuenow={ariaLabel ? pct : undefined}
      aria-valuemin={ariaLabel ? 0 : undefined}
      aria-valuemax={ariaLabel ? 100 : undefined}
      aria-label={ariaLabel}
      className={cn('w-full overflow-hidden rounded-full bg-subtle', heights[height], className)}
    >
      <div
        className={cn(
          'h-full rounded-full transition-[width] duration-fast ease-out',
          variantFill[variant],
        )}
        style={{ width: `${pct}%` }}
      />
    </div>
  );
}
