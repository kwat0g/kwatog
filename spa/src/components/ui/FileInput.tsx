import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface FileInputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  helper?: string;
  error?: string;
  containerClassName?: string;
}

/**
 * Native file input with one house style for the browse button. Every upload
 * form in the app used to hand-roll its own `file:` classes, so the button
 * size and hover state drifted page to page.
 *
 * For drop zones (attendance import, driver photo capture) keep the hidden
 * input + <Button> pattern — this component is for plain "pick a file" fields.
 */
export const FileInput = forwardRef<HTMLInputElement, FileInputProps>(
  ({ label, helper, error, required, id, className, containerClassName, ...rest }, ref) => {
    const fileId = id ?? `file-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
    return (
      <div className={cn('flex flex-col gap-1', containerClassName)}>
        {label && (
          <label htmlFor={fileId} className="text-xs text-muted font-medium">
            {label}
            {required && <span className="text-danger ml-0.5">*</span>}
          </label>
        )}
        <input
          ref={ref}
          id={fileId}
          type="file"
          required={required}
          aria-invalid={!!error}
          aria-describedby={error ? `${fileId}-error` : helper ? `${fileId}-helper` : undefined}
          className={cn(
            'block w-full text-xs text-secondary cursor-pointer',
            'file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border file:cursor-pointer',
            'file:text-xs file:font-medium file:bg-elevated file:text-primary',
            'file:transition-colors file:duration-fast hover:file:bg-subtle',
            error ? 'file:border-danger' : 'file:border-default',
            className,
          )}
          {...rest}
        />
        {error && (
          <span id={`${fileId}-error`} className="text-xs text-danger">{error}</span>
        )}
        {helper && !error ? (
          <span id={`${fileId}-helper`} className="text-xs text-muted">{helper}</span>
        ) : null}
      </div>
    );
  },
);
FileInput.displayName = 'FileInput';
