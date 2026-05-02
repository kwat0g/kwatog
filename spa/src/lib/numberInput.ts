import type { KeyboardEvent, WheelEvent } from 'react';

// Shared helpers to make HTML <input type="number"> behave sanely:
//
//  - Block letters and stray characters at keystroke time (browsers vary —
//    Chromium silently *accepts* "abc" into a number field, only blanking
//    `valueAsNumber`; the raw string still flows into our react-hook-form
//    state and looks like accepted input to the user).
//  - Disable mouse-wheel scroll which silently increments/decrements numbers
//    and is the source of countless data-entry mistakes.
//
// Apply via spreading: `<Input type="number" {...numberInputProps()} />`.

const ALLOWED_KEYS = new Set([
  'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
  'Home', 'End', 'PageUp', 'PageDown',
  'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
]);

export interface NumberInputOpts {
  /** Allow decimal point. Default: true. */
  decimal?: boolean;
  /** Allow leading minus sign. Default: false (most ERP fields are non-negative). */
  negative?: boolean;
}

export function onNumberKeyDown(opts: NumberInputOpts = {}) {
  const { decimal = true, negative = false } = opts;
  return (e: KeyboardEvent<HTMLInputElement>) => {
    // Allow Ctrl/Cmd + key combos (copy/paste/select-all/etc.)
    if (e.ctrlKey || e.metaKey) return;

    if (ALLOWED_KEYS.has(e.key)) return;

    // Digits
    if (e.key >= '0' && e.key <= '9') return;

    // Decimal point — only one allowed
    if (decimal && (e.key === '.' || e.key === ',')) {
      const t = e.currentTarget;
      if (t.value.includes('.')) { e.preventDefault(); return; }
      return;
    }

    // Minus sign — only at the start
    if (negative && e.key === '-') {
      const t = e.currentTarget;
      if ((t.selectionStart ?? 0) !== 0 || t.value.includes('-')) { e.preventDefault(); return; }
      return;
    }

    e.preventDefault();
  };
}

export function onNumberWheel(e: WheelEvent<HTMLInputElement>): void {
  // Prevent the native step-on-scroll behavior. The user is almost certainly
  // trying to scroll the page, not nudge a number value.
  (e.target as HTMLInputElement).blur();
}

/** Spread directly into an `<Input type="number">` to enforce safe entry. */
export function numberInputProps(opts: NumberInputOpts = {}) {
  return {
    inputMode: (opts.decimal === false ? 'numeric' : 'decimal') as 'numeric' | 'decimal',
    onKeyDown: onNumberKeyDown(opts),
    onWheel: onNumberWheel,
  };
}
