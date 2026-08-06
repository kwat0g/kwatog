import { forwardRef, useSyncExternalStore, type InputHTMLAttributes, type ReactNode } from 'react';
import { Check, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import type { FieldSize } from './Select';
import { getFunctionalCurrency, subscribeFunctionalCurrency } from '@/lib/runtimeCurrency';

// Matches <Select>'s scale so a field and a dropdown sitting side by side line
// up. `size` is taken by the native attribute, hence `fieldSize`.
const shellSize: Record<FieldSize, string> = {
 sm: 'h-7',
 md: 'h-8',
 lg: 'h-11',
 xl: 'h-14',
};

const textSize: Record<FieldSize, string> = {
 sm: 'px-2 text-xs',
 md: 'px-3 text-sm',
 lg: 'px-3 text-base',
 xl: 'px-4 text-xl',
};

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
 label?: string;
 helper?: string;
 error?: string;
 prefix?: ReactNode;
 suffix?: ReactNode;
 containerClassName?: string;
 fieldSize?: FieldSize;
 /** Series X / Task X2 — show inline check / X based on validation state. */
 validState?: 'idle' | 'valid' | 'invalid';
}

// Non-text input types that open a native picker — render with the same
// bg-elevated treatment as <Select> so users can tell them apart from plain text inputs.
const PICKER_TYPES = new Set(['date', 'time', 'datetime-local', 'month', 'week', 'color']);

export const Input = forwardRef<HTMLInputElement, InputProps>(
 (
 {
 label,
 helper,
 error,
 prefix,
 suffix,
 required,
 id,
 className,
 containerClassName,
 type,
 fieldSize = 'md',
 validState = 'idle',
 ...rest
 },
 ref,
 ) => {
 const inputId = id ?? `input-${rest.name ?? Math.random().toString(36).slice(2, 8)}`;
 // Resolve legacy peso markers from the live accounting policy. The
 // subscription matters on first paint: AppLayout loads policy data
 // asynchronously, after many forms have already mounted.
 const currency = useSyncExternalStore(subscribeFunctionalCurrency, getFunctionalCurrency, () => null);
 const resolvedPrefix = prefix === '₱' ? (currency ?? '') : prefix;
 const isPicker = type && PICKER_TYPES.has(type);

 // Inline validation icon (X2). Error always wins.
 const showValidIcon = validState === 'valid' && !error;
 const showInvalidIcon = validState === 'invalid' || !!error;
 const validIcon = showInvalidIcon ? (
 <X size={12} className="text-danger" aria-hidden />
 ) : showValidIcon ? (
 <Check size={12} className="text-success" aria-hidden />
 ) : null;

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
  'flex items-stretch rounded-md border overflow-hidden transition-all duration-fast',
  shellSize[fieldSize],
  'hover:border-strong focus-within:ring-[3px] focus-within:ring-accent/20 focus-within:border-accent focus-within:bg-canvas',
  isPicker ? 'bg-elevated hover:bg-canvas cursor-pointer' : 'bg-canvas',
  error ? 'border-danger' : 'border-default',
  )}
 >
 {resolvedPrefix && (
 <span className="flex items-center px-2 text-xs text-muted bg-elevated border-r border-default">
 {resolvedPrefix}
 </span>
 )}
 <input
 ref={ref}
 id={inputId}
 type={type}
 aria-invalid={!!error}
 aria-describedby={error ? `${inputId}-error` : helper ? `${inputId}-helper` : undefined}
 className={cn(
 'flex-1 min-w-0 bg-transparent placeholder:text-subtle outline-none',
 textSize[fieldSize],
 className,
 )}
 {...rest}
 />
 {validIcon && (
 <span className="flex items-center px-2" aria-hidden>
 {validIcon}
 </span>
 )}
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
