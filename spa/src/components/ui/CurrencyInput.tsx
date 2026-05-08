// Series X / Task X2 — money-formatted text input.
//
// Stores the raw decimal string ("486500.00") in form state but displays it
// with thousands separators ("486,500.00"). The user can type either way —
// commas are stripped on save, the value reformats on blur.
//
// Wraps the existing <Input> primitive; consumes/produces value as a string
// to match how decimals flow through the rest of the app (Laravel decimal
// columns serialize as strings).

import { forwardRef, useEffect, useState, type ChangeEvent, type FocusEvent } from 'react';
import { Input, type InputProps } from './Input';
import { formatCurrencyDisplay, parseCurrencyInput } from '@/lib/numberInput';

export interface CurrencyInputProps
  extends Omit<InputProps, 'value' | 'defaultValue' | 'onChange' | 'type'> {
  /** Raw decimal string. */
  value?: string | null;
  /** Called with the cleaned raw decimal string ("486500.00" or ""). */
  onChange?: (raw: string) => void;
  /** Whether to show the ₱ peso prefix. Default: true. */
  pesoPrefix?: boolean;
}

export const CurrencyInput = forwardRef<HTMLInputElement, CurrencyInputProps>(
  ({ value, onChange, onBlur, onFocus, pesoPrefix = true, prefix, className, ...rest }, ref) => {
    // Display state — formatted while idle/blurred, kept editable while focused.
    const [display, setDisplay] = useState<string>(() => formatCurrencyDisplay(value ?? ''));
    const [focused, setFocused] = useState(false);

    // Sync external changes when not actively editing.
    useEffect(() => {
      if (!focused) setDisplay(formatCurrencyDisplay(value ?? ''));
    }, [value, focused]);

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
      const next = e.target.value;
      setDisplay(next);
      onChange?.(parseCurrencyInput(next));
    };

    const handleFocus = (e: FocusEvent<HTMLInputElement>) => {
      setFocused(true);
      // While editing, show the cleaned value (no commas) so cursor math stays sane.
      setDisplay(parseCurrencyInput(display) || '');
      onFocus?.(e);
    };

    const handleBlur = (e: FocusEvent<HTMLInputElement>) => {
      setFocused(false);
      const raw = parseCurrencyInput(display);
      onChange?.(raw);
      setDisplay(formatCurrencyDisplay(raw));
      onBlur?.(e);
    };

    return (
      <Input
        ref={ref}
        type="text"
        inputMode="decimal"
        prefix={prefix ?? (pesoPrefix ? '₱' : undefined)}
        value={display}
        onChange={handleChange}
        onFocus={handleFocus}
        onBlur={handleBlur}
        className={className ? `font-mono tabular-nums ${className}` : 'font-mono tabular-nums'}
        {...rest}
      />
    );
  },
);
CurrencyInput.displayName = 'CurrencyInput';
