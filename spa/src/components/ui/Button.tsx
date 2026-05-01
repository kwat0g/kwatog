import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Spinner } from './Spinner';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost';
type Size = 'sm' | 'md' | 'lg';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
}

const sizeClasses: Record<Size, string> = {
  sm: 'h-7 px-2.5 text-xs',
  md: 'h-8 px-3 text-sm',
  lg: 'h-9 px-4 text-sm',
};

const variantClasses: Record<Variant, string> = {
  primary:
    'bg-accent text-accent-fg font-medium hover:bg-accent-hover active:scale-[0.98]',
  secondary:
    'border border-default bg-canvas text-primary hover:bg-elevated active:scale-[0.98]',
  danger:
    'bg-danger text-white font-medium hover:opacity-90 active:scale-[0.98]',
  ghost:
    'bg-transparent text-primary hover:bg-elevated active:scale-[0.98]',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ variant = 'secondary', size = 'md', loading, icon, disabled, className, children, ...rest }, ref) => {
    const isDisabled = disabled || loading;
    return (
      <button
        ref={ref}
        disabled={isDisabled}
        aria-busy={loading || undefined}
        className={cn(
          'inline-flex items-center justify-center gap-1.5 rounded-md transition-colors duration-fast',
          'disabled:opacity-60 disabled:cursor-not-allowed disabled:active:scale-100',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1',
          sizeClasses[size],
          variantClasses[variant],
          className,
        )}
        {...rest}
      >
        {loading ? <Spinner size={size === 'lg' ? 'md' : 'sm'} /> : icon}
        {children}
      </button>
    );
  },
);
Button.displayName = 'Button';
