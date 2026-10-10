import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import { usePendingProductionSubmission } from './usePendingProductionSubmission';

describe('pending production submission recovery', () => {
  beforeEach(() => sessionStorage.clear());
  it('restores the frozen payload and key after reload without submitting', () => {
    const submission = { key: 'same-request', data: { crew_id: 'old-crew', good_count: 8 } };
    const first = renderHook(() =>
      usePendingProductionSubmission<typeof submission.data>('user-1:wo-1:output'),
    );
    act(() => {
      expect(first.result.current.remember(submission)).toBe(true);
    });
    first.unmount();
    const restored = renderHook(() =>
      usePendingProductionSubmission<typeof submission.data>('user-1:wo-1:output'),
    );
    expect(restored.result.current.pending).toEqual(submission);
    const otherUser = renderHook(() =>
      usePendingProductionSubmission<typeof submission.data>('user-2:wo-1:output'),
    );
    expect(otherUser.result.current.pending).toBeNull();
    act(() => restored.result.current.clear());
    restored.unmount();
    expect(
      renderHook(() => usePendingProductionSubmission<typeof submission.data>('user-1:wo-1:output'))
        .result.current.pending,
    ).toBeNull();
  });
});

it('refuses to send an unprotected request when storage is unavailable', () => {
  const original = Storage.prototype.setItem;
  Storage.prototype.setItem = () => {
    throw new Error('Storage blocked');
  };
  try {
    const hook = renderHook(() =>
      usePendingProductionSubmission<{ good_count: number }>('user-1:wo-2:output'),
    );
    act(() => {
      expect(hook.result.current.remember({ key: 'key', data: { good_count: 1 } })).toBe(false);
    });
    expect(hook.result.current.pending).toBeNull();
    expect(hook.result.current.storageError).toContain('Nothing was sent.');
  } finally {
    Storage.prototype.setItem = original;
  }
});
