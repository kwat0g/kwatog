import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * One control for the "pick one of a few" pattern that had grown six different
 * hand-rolled looks across the app (dashboard range switchers, calendar view
 * toggles, action-center category tabs, table density pickers).
 *
 * Radio semantics, not buttons: arrow keys move between options and only the
 * selected option is a tab stop, which is what a screen reader user expects
 * from a single-choice control.
 */

export interface SegmentedOption<T extends string> {
  value: T;
  label: ReactNode;
  /** Right-aligned count, rendered in mono like every other number. */
  count?: number;
  /** Accessible name when `label` is an icon. */
  ariaLabel?: string;
  title?: string;
  disabled?: boolean;
}

type Size = 'sm' | 'md';

interface SegmentedControlProps<T extends string> {
  options: Array<SegmentedOption<T>>;
  value: T;
  onChange: (value: T) => void;
  /** Names the group for assistive tech, e.g. "Time range". */
  label: string;
  size?: Size;
  className?: string;
}

const sizeClasses: Record<Size, string> = {
  sm: 'h-7 px-2.5 text-xs',
  md: 'h-8 px-3 text-sm',
};

export function SegmentedControl<T extends string>({
  options,
  value,
  onChange,
  label,
  size = 'md',
  className,
}: SegmentedControlProps<T>) {
  const move = (from: number, delta: number) => {
    const enabled = options.map((o, i) => (o.disabled ? -1 : i)).filter((i) => i >= 0);
    if (enabled.length === 0) return;
    const pos = enabled.indexOf(from);
    const next = enabled[(pos + delta + enabled.length) % enabled.length];
    onChange(options[next].value);
  };

  return (
    <div
      role="radiogroup"
      aria-label={label}
      className={cn(
        'inline-flex items-center rounded-md border border-default bg-canvas overflow-hidden',
        className,
      )}
    >
      {options.map((opt, i) => {
        const selected = opt.value === value;
        return (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={selected}
            aria-label={opt.ariaLabel}
            title={opt.title}
            disabled={opt.disabled}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(opt.value)}
            onKeyDown={(e) => {
              if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                move(i, 1);
              } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                move(i, -1);
              }
            }}
            className={cn(
              'inline-flex items-center gap-1.5 transition-colors duration-fast cursor-pointer',
              'border-r border-default last:border-r-0',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset',
              'disabled:opacity-60 disabled:cursor-not-allowed',
              sizeClasses[size],
              selected
                ? 'bg-accent text-accent-fg font-medium'
                : 'text-secondary hover:bg-elevated',
            )}
          >
            {opt.label}
            {opt.count !== undefined && (
              <span className={cn('font-mono tabular-nums', selected ? '' : 'text-muted')}>
                {opt.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

/**
 * Multi-select sibling of SegmentedControl — independent on/off filters
 * (severity chips, calendar layers) rather than one-of-N.
 */
interface ToggleChipProps {
  active: boolean;
  onClick: () => void;
  children: ReactNode;
  ariaLabel?: string;
  className?: string;
}

export function ToggleChip({ active, onClick, children, ariaLabel, className }: ToggleChipProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      aria-label={ariaLabel}
      className={cn(
        'inline-flex items-center gap-1.5 h-7 px-2.5 rounded-md border text-xs transition-colors duration-fast cursor-pointer',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1',
        active
          ? 'border-default bg-elevated text-primary font-medium'
          : 'border-subtle text-muted hover:border-default hover:text-primary',
        className,
      )}
    >
      {children}
    </button>
  );
}
