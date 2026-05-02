import { forwardRef, type SelectHTMLAttributes } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/cn';

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  helper?: string;
  error?: string;
  containerClassName?: string;
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  ({ label, helper, error, required, id, className, containerClassName, children, ...rest }, ref) => {
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
            'relative flex items-stretch h-8 rounded-md border bg-elevated overflow-hidden transition-colors duration-fast',
            'focus-within:ring-2 focus-within:ring-accent focus-within:border-accent focus-within:bg-canvas',
            'hover:bg-canvas',
            error ? 'border-danger' : 'border-default',
          )}
        >
          <select
            ref={ref}
            id={selectId}
            aria-invalid={!!error}
            className={cn(
              'flex-1 pl-3 pr-8 text-sm bg-transparent appearance-none outline-none cursor-pointer',
              'disabled:cursor-not-allowed disabled:opacity-60',
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
            <ChevronDown size={14} />
          </span>
        </div>
        {error ? (
          <span className="text-xs text-danger">{error}</span>
        ) : helper ? (
          <span className="text-xs text-muted">{helper}</span>
        ) : null}
      </div>
    );
  },
);
Select.displayName = 'Select';
