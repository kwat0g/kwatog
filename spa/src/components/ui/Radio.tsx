import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface RadioProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: string;
}

export const Radio = forwardRef<HTMLInputElement, RadioProps>(
  ({ label, id, className, ...rest }, ref) => {
    const rid = id ?? `radio-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
    return (
      <label htmlFor={rid} className={cn('inline-flex items-center gap-2 cursor-pointer text-sm', className)}>
        <input
          ref={ref}
          id={rid}
          type="radio"
          className="h-3.5 w-3.5 border-default text-accent focus:ring-2 focus:ring-accent focus:ring-offset-1"
          {...rest}
        />
        {label && <span className="select-none">{label}</span>}
      </label>
    );
  },
);
Radio.displayName = 'Radio';
