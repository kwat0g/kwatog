import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Spinner } from './Spinner';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost';
type Size = 'xs' | 'sm' | 'md' | 'lg' | 'touch';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
  /** Square button with no label. Requires an `aria-label`. */
  iconOnly?: boolean;
}

const sizeClasses: Record<Size, string> = {
  xs: 'h-6 px-2 text-[11px]',
  sm: 'h-7 px-3 text-xs',
  md: 'h-8 px-4 text-sm',
  lg: 'h-9 px-5 text-base font-medium',
  // 44px — the touch-target floor the PWAs use. Deliberately larger than the
  // desktop scale in DESIGN-SYSTEM.md, which assumes a mouse. Hardcoded rather
  // than `min-h-hit`, which resolves to --hit-min: 28px outside a floor route —
  // a Button is placed by the page author, not by the palette, so the target
  // must not shrink when the same component renders on a desk screen.
  touch: 'min-h-hit px-4 text-base',
};

const iconOnlySizeClasses: Record<Size, string> = {
  xs: 'h-6 w-6 text-[11px] rounded',
  sm: 'h-7 w-7 text-xs rounded-md',
  md: 'h-8 w-8 text-sm rounded-md',
  lg: 'h-9 w-9 text-base rounded-md',
  // Square 44×44 — same touch-target floor, both axes.
  touch: 'min-h-hit min-w-hit text-base rounded-md',
};

const variantClasses: Record<Variant, string> = {
  primary:
    'bg-accent text-accent-fg font-medium border-hairline border-transparent hover:bg-accent-hover active:scale-[0.98] transition-colors',
  secondary:
    'border-hairline border-default bg-transparent text-primary hover:bg-elevated active:scale-[0.98] transition-colors',
  // Saturated --danger as the fill, not the pale --danger-bg tint. The tint is a
  // chip/badge surface meant to carry --danger-fg ink; used as a button fill under
  // white text it rendered at 1.23:1 in the office palette. ConfirmDialog maps BOTH
  // its danger and warning variants here, so this is the primary CTA on every
  // destructive confirm in the app.
  danger:
    'bg-danger text-accent-fg font-medium border-hairline border-transparent hover:bg-danger/90 active:scale-[0.98] transition-colors',
  ghost:
    'bg-transparent text-primary border-hairline border-transparent hover:bg-subtle/80 active:scale-[0.98] transition-colors',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  (
    {
      variant = 'secondary',
      size = 'md',
      loading,
      icon,
      iconOnly,
      disabled,
      className,
      children,
      ...rest
    },
    ref,
  ) => {
    const isDisabled = disabled || loading;
    return (
      <button
        ref={ref}
        disabled={isDisabled}
        aria-busy={loading || undefined}
        className={cn(
          'inline-flex items-center justify-center gap-2 rounded-md transition-colors duration-fast cursor-pointer',
          'disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100 disabled:shadow-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
          iconOnly ? iconOnlySizeClasses[size] : sizeClasses[size],
          variantClasses[variant],
          className,
        )}
        {...rest}
      >
        {loading ? (
          <Spinner size={size === 'sm' || size === 'xs' || size === 'md' ? 'sm' : 'md'} />
        ) : (
          icon
        )}
        {children}
      </button>
    );
  },
);
Button.displayName = 'Button';
