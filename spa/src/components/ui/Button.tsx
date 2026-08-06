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
 sm: 'h-8 px-3 text-xs',
 md: 'h-10 px-5 text-sm',
 lg: 'h-12 px-7 text-base font-medium',
 xl: 'h-[56px] px-8 text-lg font-medium gap-2.5',
};

const iconOnlySizeClasses: Record<Size, string> = {
 sm: 'h-8 w-8 text-xs rounded-full',
 md: 'h-10 w-10 text-sm rounded-full',
 lg: 'h-12 w-12 text-base rounded-full',
 xl: 'h-[56px] w-[56px] text-lg rounded-full',
};

const variantClasses: Record<Variant, string> = {
 primary:
 'bg-accent text-accent-fg font-medium shadow-[0_6px_20px_0_var(--ring)] hover:bg-accent-hover hover:-translate-y-1 hover:shadow-[0_8px_25px_0_var(--ring)] active:translate-y-0 active:scale-[0.97] transition-all',
 secondary:
 'border border-default bg-canvas/80 text-primary backdrop-blur-md shadow-sm hover:bg-elevated/90 hover:-translate-y-1 hover:shadow-md active:translate-y-0 active:scale-[0.97] transition-all',
 danger:
 'bg-danger text-white font-medium shadow-[0_4px_14px_0_rgba(239,68,68,0.39)] hover:bg-danger/90 hover:-translate-y-0.5 active:translate-y-0 active:scale-[0.98] transition-all',
 success:
 'bg-success text-white font-medium shadow-[0_4px_14px_0_rgba(16,185,129,0.39)] hover:bg-success/90 hover:-translate-y-0.5 active:translate-y-0 active:scale-[0.98] transition-all',
 ghost:
 'bg-transparent text-primary hover:bg-subtle/80 active:scale-[0.98] transition-colors',
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
 'inline-flex items-center justify-center gap-2 rounded-lg transition-all duration-normal cursor-pointer',
 'disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100 disabled:hover:translate-y-0 disabled:shadow-none',
 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
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
