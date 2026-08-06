import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
 /** Rich labels are allowed so a checkbox can carry a helper line beside its name. */
 label?: ReactNode;
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(
 ({ label, id, className, ...rest }, ref) => {
 const cbId = id ?? `cb-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
 return (
 <label htmlFor={cbId} className={cn('inline-flex items-center gap-2 cursor-pointer text-sm', className)}>
 <input
 ref={ref}
 id={cbId}
 type="checkbox"
 className="h-3.5 w-3.5 rounded border-default text-accent focus:ring-2 focus:ring-accent focus:ring-offset-1"
 {...rest}
 />
 {label && <span className="select-none">{label}</span>}
 </label>
 );
 },
);
Checkbox.displayName = 'Checkbox';
