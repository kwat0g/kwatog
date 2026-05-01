import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  helper?: string;
  error?: string;
  prefix?: ReactNode;
  suffix?: ReactNode;
  containerClassName?: string;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ label, helper, error, prefix, suffix, required, id, className, containerClassName, ...rest }, ref) => {
    const inputId = id ?? `input-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
    return (
      <div className={cn('flex flex-col gap-1', containerClassName)}>
        {label && (
          <label htmlFor={inputId} className="text-xs text-muted font-medium">
            {label}
            {required && <span className="text-danger ml-0.5">*</span>}
          </label>
        )}
        <div
          className={cn(
            'flex items-stretch h-8 rounded-md border bg-canvas overflow-hidden',
            'focus-within:ring-2 focus-within:ring-accent focus-within:border-accent',
            error ? 'border-danger' : 'border-default',
          )}
        >
          {prefix && (
            <span className="flex items-center px-2 text-xs text-muted bg-elevated border-r border-default">
              {prefix}
            </span>
          )}
          <input
            ref={ref}
            id={inputId}
            aria-invalid={!!error}
            aria-describedby={error ? `${inputId}-error` : helper ? `${inputId}-helper` : undefined}
            className={cn(
              'flex-1 px-3 text-sm bg-transparent placeholder:text-text-subtle outline-none',
              className,
            )}
            {...rest}
          />
          {suffix && (
            <span className="flex items-center px-2 text-xs text-muted bg-elevated border-l border-default">
              {suffix}
            </span>
          )}
        </div>
        {error ? (
          <span id={`${inputId}-error`} className="text-xs text-danger">
            {error}
          </span>
        ) : helper ? (
          <span id={`${inputId}-helper`} className="text-xs text-muted">
            {helper}
          </span>
        ) : null}
      </div>
    );
  },
);
Input.displayName = 'Input';
