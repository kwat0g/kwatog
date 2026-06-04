import { describe, it, expect, vi, beforeEach } from 'vitest';
import { applyServerValidationErrors } from '../formErrors';
import type { UseFormSetError } from 'react-hook-form';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';

// react-hot-toast uses DOM APIs unavailable in jsdom; mock the whole module.
vi.mock('react-hot-toast', () => ({
  default: {
    error: vi.fn(),
    success: vi.fn(),
  },
}));

function makeAxiosError(status: number, data: Record<string, unknown>): AxiosError {
  const error = new AxiosError('Request failed');
  error.response = {
    status,
    data,
    headers: {},
    config: {} as never,
    statusText: '',
  };
  return error;
}

describe('applyServerValidationErrors', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('maps Laravel 422 field errors to form fields', () => {
    const setError = vi.fn() as unknown as UseFormSetError<{ email: string }>;
    const error = makeAxiosError(422, {
      message: 'The given data was invalid.',
      errors: { email: ['The email has already been taken.'] },
    });

    const handled = applyServerValidationErrors(error, setError);

    expect(handled).toBe(true);
    expect(setError).toHaveBeenCalledWith('email', {
      type: 'server',
      message: 'The email has already been taken.',
    });
    expect(toast.error).toHaveBeenCalledWith(
      'The server flagged some fields. Please review and try again.',
    );
  });

  it('maps multiple field errors to form fields', () => {
    const setError = vi.fn() as unknown as UseFormSetError<Record<string, string>>;
    const error = makeAxiosError(422, {
      message: 'The given data was invalid.',
      errors: {
        email: ['The email has already been taken.'],
        name: ['The name field is required.'],
      },
    });

    const handled = applyServerValidationErrors(error, setError);

    expect(handled).toBe(true);
    expect(setError).toHaveBeenCalledTimes(2);
    expect(setError).toHaveBeenCalledWith('email', {
      type: 'server',
      message: 'The email has already been taken.',
    });
    expect(setError).toHaveBeenCalledWith('name', {
      type: 'server',
      message: 'The name field is required.',
    });
  });

  it('handles 422 with only a message (no errors object)', () => {
    const setError = vi.fn() as unknown as UseFormSetError<Record<string, string>>;
    const error = makeAxiosError(422, {
      message: 'Something went wrong.',
    });

    const handled = applyServerValidationErrors(error, setError);

    expect(handled).toBe(true);
    expect(setError).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith('Something went wrong.');
  });

  it('returns false for non-422 errors', () => {
    const setError = vi.fn() as unknown as UseFormSetError<Record<string, string>>;
    const error = makeAxiosError(500, { message: 'Server error' });

    const handled = applyServerValidationErrors(error, setError);

    expect(handled).toBe(false);
  });

  it('returns false for non-axios errors', () => {
    const setError = vi.fn() as unknown as UseFormSetError<Record<string, string>>;
    const handled = applyServerValidationErrors(new Error('plain'), setError);

    expect(handled).toBe(false);
  });
});
