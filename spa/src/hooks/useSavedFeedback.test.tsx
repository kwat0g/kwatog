import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSavedFeedback } from './useSavedFeedback';

describe('useSavedFeedback', () => {
  afterEach(() => vi.useRealTimers());

  it('shows Saved briefly, then calls the expiry callback', () => {
    vi.useFakeTimers();
    const onExpire = vi.fn();
    const { result } = renderHook(() => useSavedFeedback({ duration: 900, onExpire }));

    act(() => result.current.markSaved());
    expect(result.current.saved).toBe(true);

    act(() => vi.advanceTimersByTime(900));
    expect(result.current.saved).toBe(false);
    expect(onExpire).toHaveBeenCalledTimes(1);
  });
});
