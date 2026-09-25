import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import type { FieldErrors, FieldValues, UseFormSetError, Path } from 'react-hook-form';
import type { ApiValidationError } from '@/types';
import { wasReportedGlobally } from '@/api/client';

/**
 * Recursively collect all error messages from a FieldErrors tree.
 * Handles nested objects (like `items.0.product_id`) and array-level
 * root messages (like `defects.root.message`).
 */
type ValidationMessage = { path: string; message: string };

function humanizePath(path: string): string {
 return path
  .replace(/\.(\d+)(?=\.)/g, ' $1')
  .replace(/[._-]+/g, ' ')
  .replace(/\b\w/g, (char) => char.toUpperCase());
}

function collectMessages(errors: Record<string, unknown>, parentPath = ''): ValidationMessage[] {
 const msgs: ValidationMessage[] = [];

 for (const [key, val] of Object.entries(errors)) {
 if (!val || typeof val !== 'object') continue;
 const node = val as Record<string, unknown>;
 const path = parentPath ? `${parentPath}.${key}` : key;

 // Leaf error: { message: "..." }
 if (typeof node.message === 'string' && node.message) {
 msgs.push({ path, message: node.message });
 continue;
 }

 // Array root error: { root: { message: "..." } }
 const root = node.root as Record<string, unknown> | undefined;
 if (root && typeof root.message === 'string' && root.message) {
 msgs.push({ path, message: root.message });
 }

 // Recurse into nested objects / array items
 msgs.push(...collectMessages(node, path));
 }

 return msgs.filter((entry, index, all) => all.findIndex((item) => item.path === entry.path && item.message === entry.message) === index);
}

function formatValidationMessage(path: string, message: string, labels?: Record<string, string>): string {
 const label = labels?.[path] ?? humanizePath(path);
 if (/^(required|is required|this field is required)\.?$/i.test(message.trim())) return `${label} is required.`;
 if (message.toLowerCase().includes(label.toLowerCase())) return message;
 return `${label}: ${message}`;
}

export function focusFirstInvalidField(): void {
 if (typeof document === 'undefined') return;

 // RHF updates aria-invalid before invoking the invalid callback. Deferring a
 // frame lets the browser see the new attributes and keeps keyboard users at
 // the first field that needs attention instead of leaving focus on Submit.
 window.setTimeout(() => {
 const firstInvalid = document.querySelector<HTMLElement>(
 '[aria-invalid="true"]:not([disabled])',
 );
 firstInvalid?.focus({ preventScroll: false });
 }, 0);
}

export function onFormInvalid<T extends FieldValues>(
 labels?: Partial<Record<keyof T & string, string>>,
): (errors: FieldErrors<T>) => void {
 return (errors) => {
 focusFirstInvalidField();
 const messages = collectMessages(errors as Record<string, unknown>).map(({ path, message }) =>
  formatValidationMessage(path, message, labels as Record<string, string> | undefined),
 );

 if (messages.length === 0) {
 toast.error('Enter the required fields before submitting.', { duration: 5000 });
 return;
 }

 if (messages.length === 1) {
 toast.error(messages[0], { duration: 5000 });
 return;
 }

 // Multiple: show up to 3 specific messages, then "and N more"
 const head = messages.slice(0, 3);
 const more = messages.length > 3 ? `\n• …and ${messages.length - 3} more` : '';
 toast.error(`Please fix the following:\n• ${head.join('\n• ')}${more}`, { duration: 6000 });
 };
}


/**
 * Map a Laravel 422 response into RHF `setError` calls and surface a toast.
 * Returns true if the error was handled, false otherwise (so callers can
 * fall back to a generic toast).
 */
export function applyServerValidationErrors<T extends FieldValues>(
 err: unknown,
 setError: UseFormSetError<T>,
 fallbackMessage = 'Failed to save. Please try again.',
): boolean {
 if (err instanceof AxiosError && err.response?.status === 422) {
 const data = err.response.data as ApiValidationError;
 if (data.errors) {
 Object.entries(data.errors).forEach(([field, msgs]) => {
 setError(field as Path<T>, { type: 'server', message: msgs[0] });
 });
 const first = Object.entries(data.errors)[0];
 if (first) {
  const [field, messages] = first;
  toast.error(formatValidationMessage(field, messages[0] ?? 'Enter a valid value.'));
 }
 // The fields are now marked aria-invalid; send the keyboard there rather
 // than leaving focus on a submit button the user just bounced off.
 focusFirstInvalidField();
 return true;
 }
 if (data.message) {
 toast.error(data.message);
 return true;
 }
 }
 reportMutationError(err, fallbackMessage);
 return false;
}

/**
 * The one place a failed mutation becomes a message.
 *
 * Two rules it exists to enforce:
 *
 * 1. Never show a raw exception string. `err.message` on an Axios rejection is
 *    "Request failed with status code 422" — meaningless to a warehouse clerk
 *    and unsearchable for support. Only a `message` the API deliberately sent
 *    is user-facing text.
 * 2. Never contradict the interceptor. It already reported timeouts, 429s,
 *    lockouts and offline failures with the actual cause; a second toast
 *    saying "Failed to create PO." would be less true, not more helpful.
 */
export function reportMutationError(err: unknown, fallbackMessage: string): void {
 if (wasReportedGlobally(err)) return;

 if (err instanceof AxiosError) {
  const data = err.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
  // A 422 with no field map still carries a usable sentence ("Budget for this
  // fiscal year already exists"). Field-level 422s are handled above.
  const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
  toast.error(firstFieldError ?? data?.message ?? fallbackMessage);
  return;
 }

 // A plain Error thrown by client-side guard code is authored copy, so it is
 // safe to show. Anything else is not a message.
 if (err instanceof Error && err.message && !/^Request failed with status code/.test(err.message)) {
  toast.error(err.message);
  return;
 }

 toast.error(fallbackMessage);
}
