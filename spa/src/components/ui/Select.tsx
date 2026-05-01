import { forwardRef, type SelectHTMLAttributes } from 'react';
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
        <select
          ref={ref}
          id={selectId}
          aria-invalid={!!error}
          className={cn(
            'h-8 px-2.5 pr-8 rounded-md border bg-canvas text-sm appearance-none',
            'focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent',
            'bg-no-repeat bg-[length:14px] bg-[position:right_8px_center]',
            "bg-[url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2214%22 height=%2214%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%2371717a%22 stroke-width=%222%22><polyline points=%226 9 12 15 18 9%22/></svg>')]",
            error ? 'border-danger' : 'border-default',
            className,
          )}
          {...rest}
        >
          {children}
        </select>
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
