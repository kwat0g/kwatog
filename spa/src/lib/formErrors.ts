import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import type { FieldErrors, FieldValues, UseFormSetError, Path } from 'react-hook-form';
import type { ApiValidationError } from '@/types';

/**
 * Build a `react-hook-form` `handleSubmit` error callback that fires a single
 * toast summarising the number of validation errors. Use as the second argument
 * to `handleSubmit(onValid, onInvalid)` so the user sees feedback even when
 * errors are below the fold.
 */
export function onFormInvalid<T extends FieldValues>(): (errors: FieldErrors<T>) => void {
  return (errors) => {
    const count = Object.keys(errors).length;
    if (count === 0) return;
    toast.error(
      count === 1
        ? 'Please fix the highlighted field before submitting.'
        : `Please fix ${count} fields before submitting.`,
    );
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
