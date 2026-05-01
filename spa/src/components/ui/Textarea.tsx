import { forwardRef, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
  helper?: string;
  containerClassName?: string;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ label, error, helper, required, id, className, containerClassName, ...rest }, ref) => {
    const taId = id ?? `ta-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
    return (
      <div className={cn('flex flex-col gap-1', containerClassName)}>
        {label && (
          <label htmlFor={taId} className="text-xs text-muted font-medium">
            {label}
            {required && <span className="text-danger ml-0.5">*</span>}
          </label>
        )}
        <textarea
          ref={ref}
          id={taId}
          aria-invalid={!!error}
          className={cn(
            'min-h-[72px] px-3 py-2 rounded-md border bg-canvas text-sm resize-y',
            'focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent',
            'placeholder:text-text-subtle',
            error ? 'border-danger' : 'border-default',
            className,
          )}
          {...rest}
        />
        {error ? (
          <span className="text-xs text-danger">{error}</span>
        ) : helper ? (
          <span className="text-xs text-muted">{helper}</span>
        ) : null}
      </div>
    );
  },
);
Textarea.displayName = 'Textarea';
