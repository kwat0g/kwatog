import { forwardRef, useId, useState, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
  helper?: string;
  containerClassName?: string;
  /** Series X / Task X2 — show a "n / max" counter when maxLength is set. Default true. */
  showCounter?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  (
    {
      label,
      error,
      helper,
      required,
      id,
      className,
      containerClassName,
      maxLength,
      showCounter = true,
      defaultValue,
      value,
      onChange,
      ...rest
    },
    ref,
  ) => {
    const generatedId = useId().replaceAll(':', '');
    const taId = id ?? `textarea-${generatedId}`;

    // Track current length for the counter. We mirror the value when the
    // textarea is uncontrolled so the counter still updates.
    const [internalLen, setInternalLen] = useState<number>(() => {
      if (typeof value === 'string') return value.length;
      if (typeof defaultValue === 'string') return defaultValue.length;
      return 0;
    });
    const length = typeof value === 'string' ? value.length : internalLen;

    const counterEnabled = showCounter && typeof maxLength === 'number' && maxLength > 0;
    const ratio = counterEnabled ? length / (maxLength as number) : 0;
    const counterColor =
      ratio >= 1 ? 'text-danger-fg' : ratio >= 0.9 ? 'text-warning-fg' : 'text-muted';

    return (
      <div className={cn('flex flex-col gap-1', containerClassName)}>
        {label && (
          <label htmlFor={taId} className="text-label text-muted font-medium">
            {label}
            {required && <span aria-hidden="true" className="text-danger-fg ml-0.5">*</span>}
          </label>
        )}
        <textarea
          data-touch-target
          ref={ref}
          id={taId}
          required={required}
          aria-invalid={!!error}
          aria-describedby={error ? `${taId}-error` : helper ? `${taId}-helper` : undefined}
          maxLength={maxLength}
          defaultValue={defaultValue}
          value={value}
          onChange={(e) => {
            if (typeof value !== 'string') setInternalLen(e.target.value.length);
            onChange?.(e);
          }}
          className={cn(
            'min-h-[72px] px-3 py-2 rounded-md border bg-canvas text-md resize-y transition-colors duration-fast',
            'hover:border-strong focus:outline-none focus:ring-[3px] focus:ring-accent/20 focus:border-accent',
            'placeholder:text-subtle',
            error ? 'border-danger' : 'border-default',
            className,
          )}
          {...rest}
        />
        <div className="flex items-center justify-between">
          {error ? (
            <span id={`${taId}-error`} role="alert" className="text-caption text-danger-fg">
              {error}
            </span>
          ) : helper ? (
            <span id={`${taId}-helper`} className="text-caption text-muted">
              {helper}
            </span>
          ) : (
            <span />
          )}
          {counterEnabled && (
            <span className={cn('text-caption font-sans tabular-nums', counterColor)}>
              {length} / {maxLength}
            </span>
          )}
        </div>
      </div>
    );
  },
);
Textarea.displayName = 'Textarea';
