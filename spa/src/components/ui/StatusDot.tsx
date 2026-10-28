import { cn } from '@/lib/cn';

export type StatusDotVariant = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const dotClasses: Record<StatusDotVariant, string> = {
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  info: 'bg-info',
  neutral: 'bg-strong',
};

const textClasses: Record<StatusDotVariant, string> = {
  success: 'text-success-fg',
  warning: 'text-warning-fg',
  danger: 'text-danger-fg',
  info: 'text-info-fg',
  neutral: 'text-secondary',
};

/** A semantic state cue; the visible label keeps meaning independent of hue. */
export function StatusDot({
  label,
  variant,
  className,
}: {
  label: string;
  variant: StatusDotVariant;
  className?: string;
}) {
  return (
    <span className={cn('inline-flex items-center gap-1.5 text-xs font-medium', textClasses[variant], className)}>
      <span aria-hidden="true" className={cn('h-1.5 w-1.5 shrink-0 rounded-full', dotClasses[variant])} />
      {label}
    </span>
  );
}
