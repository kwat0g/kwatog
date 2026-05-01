import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface SwitchProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: string;
  description?: string;
}

export const Switch = forwardRef<HTMLInputElement, SwitchProps>(
  ({ label, description, id, className, ...rest }, ref) => {
    const sid = id ?? `sw-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
    return (
      <label htmlFor={sid} className={cn('inline-flex items-start gap-2 cursor-pointer', className)}>
        <input ref={ref} id={sid} type="checkbox" className="peer sr-only" {...rest} />
        <span className="relative inline-block h-4 w-7 rounded-full bg-elevated border border-default peer-checked:bg-accent peer-focus-visible:ring-2 peer-focus-visible:ring-accent peer-focus-visible:ring-offset-1 transition-colors duration-fast">
          <span className="absolute top-[1px] left-[1px] h-3 w-3 rounded-full bg-canvas shadow transition-transform duration-fast peer-checked:translate-x-3" />
        </span>
        {(label || description) && (
          <span className="flex flex-col leading-tight select-none">
            {label && <span className="text-sm font-medium">{label}</span>}
            {description && <span className="text-xs text-muted">{description}</span>}
          </span>
        )}
      </label>
    );
  },
);
Switch.displayName = 'Switch';
