import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import type { FieldErrors, FieldValues, UseFormSetError, Path } from 'react-hook-form';
import type { ApiValidationError } from '@/types';

/**
 * Recursively collect all error messages from a FieldErrors tree.
 * Handles nested objects (like `items.0.product_id`) and array-level
 * root messages (like `defects.root.message`).
 */
function collectMessages(errors: Record<string, any>): string[] {
  const msgs: string[] = [];

  for (const val of Object.values(errors)) {
    if (!val) continue;

    // Leaf error: { message: "..." }
    if (typeof val.message === 'string' && val.message) {
      msgs.push(val.message);
      continue;
    }

    // Array root error: { root: { message: "..." } }
    if (val.root && typeof val.root.message === 'string' && val.root.message) {
      msgs.push(val.root.message);
    }

    // Recurse into nested objects / array items
    if (typeof val === 'object') {
      msgs.push(...collectMessages(val));
    }
  }

  // Deduplicate
  return [...new Set(msgs)];
}

export function onFormInvalid<T extends FieldValues>(
  _labels?: Partial<Record<keyof T & string, string>>,
): (errors: FieldErrors<T>) => void {
  return (errors) => {
    const messages = collectMessages(errors as Record<string, any>);

    if (messages.length === 0) {
      toast.error('Please fix the highlighted fields before submitting.', { duration: 5000 });
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
      toast.error('The server flagged some fields. Please review and try again.');
      return true;
    }
    if (data.message) {
      toast.error(data.message);
      return true;
    }
  }
  toast.error(fallbackMessage);
  return false;
}
