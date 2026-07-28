import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Spinner } from './Spinner';

type Variant = 'primary' | 'secondary' | 'danger' | 'success' | 'ghost';
/** `xl` is the touch target for the factory kiosk and mobile pages (52px). */
type Size = 'sm' | 'md' | 'lg' | 'xl';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
  /** Square button with no label. Requires an `aria-label`. */
  iconOnly?: boolean;
}

const sizeClasses: Record<Size, string> = {
  sm: 'h-7 px-2.5 text-xs',
  md: 'h-8 px-3 text-sm',
  lg: 'h-9 px-4 text-sm',
  xl: 'h-[52px] px-5 text-lg gap-2',
};

const iconOnlySizeClasses: Record<Size, string> = {
  sm: 'h-7 w-7 text-xs',
  md: 'h-8 w-8 text-sm',
  lg: 'h-9 w-9 text-sm',
  xl: 'h-[52px] w-[52px] text-lg',
};

const variantClasses: Record<Variant, string> = {
  primary:
    'bg-accent text-accent-fg font-medium hover:bg-accent-hover active:scale-[0.98]',
  secondary:
    'border border-default bg-canvas text-primary hover:bg-elevated active:scale-[0.98]',
  danger:
    'bg-danger text-white font-medium hover:opacity-90 active:scale-[0.98]',
  success:
    'bg-success text-white font-medium hover:opacity-90 active:scale-[0.98]',
  ghost:
    'bg-transparent text-primary hover:bg-elevated active:scale-[0.98]',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ variant = 'secondary', size = 'md', loading, icon, iconOnly, disabled, className, children, ...rest }, ref) => {
    const isDisabled = disabled || loading;
    return (
      <button
        ref={ref}
        disabled={isDisabled}
        aria-busy={loading || undefined}
        className={cn(
          'inline-flex items-center justify-center gap-1.5 rounded-md transition-colors duration-fast cursor-pointer',
          'disabled:opacity-60 disabled:cursor-not-allowed disabled:active:scale-100',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1',
          iconOnly ? iconOnlySizeClasses[size] : sizeClasses[size],
          variantClasses[variant],
          className,
        )}
        {...rest}
      >
        {loading ? <Spinner size={size === 'sm' || size === 'md' ? 'sm' : 'md'} /> : icon}
        {children}
      </button>
    );
  },
);
Button.displayName = 'Button';
