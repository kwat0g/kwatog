import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  MAX_BOUNDARY_TIMEOUT_MS,
  scheduleBoundaryInvalidation,
} from './scheduleBoundaryInvalidation';

describe('scheduleBoundaryInvalidation', () => {
  afterEach(() => vi.useRealTimers());

  it('keeps long boundaries scheduled until the actual boundary', () => {
    vi.useFakeTimers();
    const boundary = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString();
    const invalidate = vi.fn();
    const cancel = scheduleBoundaryInvalidation(boundary, invalidate);

    vi.advanceTimersByTime(MAX_BOUNDARY_TIMEOUT_MS);
    expect(invalidate).not.toHaveBeenCalled();

    vi.advanceTimersByTime(30 * 24 * 60 * 60 * 1000 - MAX_BOUNDARY_TIMEOUT_MS + 25);
    expect(invalidate).toHaveBeenCalledTimes(1);

    cancel();
  });
});
