import { forwardRef, useCallback, type ChangeEvent } from 'react';
import { Input, type InputProps } from './Input';
import { digitsOnly, formatByKind, helperFor, placeholderFor, type PhIdKind } from '@/lib/phFormat';

export interface MaskedInputProps extends Omit<InputProps, 'onChange' | 'value' | 'defaultValue' | 'type'> {
  /** Format kind. Determines mask + max length. */
  kind: PhIdKind;
  /** Controlled value (raw or formatted; both are accepted, output is digits-only). */
  value?: string;
  /** Called with digits-only value on every change. */
  onChange?: (rawDigits: string, formatted: string) => void;
  /** Override generated helper text. */
  helper?: string;
  /** Override generated placeholder. */
  placeholder?: string;
}

/**
 * MaskedInput renders a regular text input that auto-formats Philippine IDs and mobile
 * numbers as the user types. The form receives digits-only via onChange so the
 * backend always stores the canonical value. Designed to plug into react-hook-form
 * via Controller.
 */
export const MaskedInput = forwardRef<HTMLInputElement, MaskedInputProps>(
  ({ kind, value, onChange, helper, placeholder, ...rest }, ref) => {
    const display = formatByKind(kind, value);
    const handleChange = useCallback(
      (e: ChangeEvent<HTMLInputElement>) => {
        const raw = digitsOnly(e.target.value);
        onChange?.(raw, formatByKind(kind, raw));
      },
      [kind, onChange],
    );
    return (
      <Input
        {...rest}
        ref={ref}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        value={display}
        onChange={handleChange}
        placeholder={placeholder ?? placeholderFor(kind)}
        helper={helper ?? helperFor(kind)}
        className="font-mono"
      />
    );
  },
);
MaskedInput.displayName = 'MaskedInput';
