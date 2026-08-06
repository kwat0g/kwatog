import { forwardRef, type SelectHTMLAttributes } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/cn';

/**
 * Named `fieldSize` because the native `size` attribute on <select> is a row
 * count. `sm` is for controls inside table rows, `lg` for the touch targets on
 * the factory kiosk and mobile pages, `xl` for the count entry on those same
 * pages — read at arm's length, tapped with gloves on.
 */
export type FieldSize = 'sm' | 'md' | 'lg' | 'xl';

const shellSize: Record<FieldSize, string> = {
 sm: 'h-7',
 md: 'h-8',
 lg: 'h-11',
 xl: 'h-14',
};

const textSize: Record<FieldSize, string> = {
 sm: 'pl-2 pr-7 text-xs',
 md: 'pl-3 pr-8 text-sm',
 lg: 'pl-3 pr-8 text-base',
 xl: 'pl-4 pr-9 text-xl',
};

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
 label?: string;
 helper?: string;
 error?: string;
 containerClassName?: string;
 fieldSize?: FieldSize;
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
 (
 {
 label,
 helper,
 error,
 required,
 id,
 className,
 containerClassName,
 fieldSize = 'md',
 children,
 ...rest
 },
 ref,
 ) => {
 const selectId = id ?? `select-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
 return (
 <div className={cn('flex flex-col gap-1', containerClassName)}>
 {label && (
 <label htmlFor={selectId} className="text-xs text-muted font-medium">
 {label}
 {required && <span className="text-danger ml-0.5">*</span>}
 </label>
 )}
 <div
 className={cn(
 'relative flex items-stretch rounded-xl border bg-elevated overflow-hidden transition-all duration-300 shadow-sm',
 'hover:border-strong hover:bg-canvas',
 'focus-within:ring-[3px] focus-within:ring-accent/20 focus-within:border-accent focus-within:bg-canvas',
 shellSize[fieldSize],
 error ? 'border-danger' : 'border-default',
 )}
 >
 <select
 ref={ref}
 id={selectId}
 aria-invalid={!!error}
 aria-describedby={error ? `${selectId}-error` : helper ? `${selectId}-helper` : undefined}
 className={cn(
 'flex-1 min-w-0 bg-transparent appearance-none outline-none cursor-pointer',
 'disabled:cursor-not-allowed disabled:opacity-60',
 textSize[fieldSize],
 className,
 )}
 {...rest}
 >
 {children}
 </select>
 <span
 aria-hidden
 className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-muted"
 >
 <ChevronDown size={fieldSize === 'sm' ? 12 : 14} />
 </span>
 </div>
 {error && (
 <span id={`${selectId}-error`} className="text-xs text-danger">{error}</span>
 )}
 {helper && !error ? (
 <span id={`${selectId}-helper`} className="text-xs text-muted">{helper}</span>
 ) : null}
 </div>
 );
 },
);
Select.displayName = 'Select';
