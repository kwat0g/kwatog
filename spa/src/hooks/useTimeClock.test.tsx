import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import toast from 'react-hot-toast';
import { punchApi } from '@/api/attendance/punch';
import { FAILURE_COOLDOWN_MS, useTimeClock } from './useTimeClock';

vi.mock('@/api/attendance/punch', () => ({ punchApi: { status: vi.fn(), punch: vi.fn() } }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/hooks/useFeature', () => ({ useFeature: vi.fn(() => true) }));

let mockEmployee: object | null = { id: 'e1' };
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (select: (s: { user: { employee: object | null } }) => unknown) =>
    select({ user: { employee: mockEmployee } }),
}));

const STATUS = {
  next_action: 'in' as const,
  shift: { name: 'Day', start: '06:00', end: '14:00' },
  punches: [],
  tardiness_minutes: null,
  radius_m: 200,
};

const wrapper = ({ children }: { children: ReactNode }) => (
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    {children}
  </QueryClientProvider>
);

function geolocation(impl: (ok: PositionCallback, err: PositionErrorCallback) => void) {
  const getCurrentPosition = vi.fn(impl);
  Object.defineProperty(globalThis.navigator, 'geolocation', { configurable: true, value: { getCurrentPosition } });

  return getCurrentPosition;
}

const denied = (_ok: PositionCallback, err: PositionErrorCallback) =>
  err({ code: 1, message: 'denied', PERMISSION_DENIED: 1 } as GeolocationPositionError);

function permissions(state: PermissionState | null) {
  const status = { state, onchange: null as null | (() => void) };
  Object.defineProperty(globalThis.navigator, 'permissions', {
    configurable: true,
    value: state === null ? undefined : { query: vi.fn().mockResolvedValue(status) },
  });

  return status;
}

describe('useTimeClock spam guard', () => {
  beforeEach(() => {
    mockEmployee = { id: 'e1' };
    vi.mocked(punchApi.status).mockResolvedValue(STATUS);
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  it('never asks the browser for a location while the permission is denied', async () => {
    const getCurrentPosition = geolocation(vi.fn());
    permissions('denied');
    const { result } = renderHook(() => useTimeClock(), { wrapper });

    await waitFor(() => expect(result.current.locationBlocked).toBe(true));
    act(() => result.current.punch());
    act(() => result.current.punch());

    expect(getCurrentPosition).not.toHaveBeenCalled();
    expect(result.current.canPunch).toBe(false);
  });

  it('ignores taps during the cooldown after a failed attempt, then works again', async () => {
    permissions('prompt');
    const getCurrentPosition = geolocation((_ok, err) =>
      err({ code: 2, message: 'unavailable' } as GeolocationPositionError),
    );
    const { result } = renderHook(() => useTimeClock(), { wrapper });
    await waitFor(() => expect(result.current.status.data).toBeDefined());

    act(() => result.current.punch());
    await waitFor(() => expect(result.current.failure?.code).toBe('geo_unavailable'));
    expect(result.current.cooldownSeconds).toBeGreaterThan(0);

    act(() => result.current.punch());
    act(() => result.current.punch());
    expect(getCurrentPosition).toHaveBeenCalledTimes(1);

    await waitFor(() => expect(result.current.cooldownSeconds).toBe(0), { timeout: FAILURE_COOLDOWN_MS + 1500 });
    act(() => result.current.punch());
    await waitFor(() => expect(getCurrentPosition).toHaveBeenCalledTimes(2));
  }, 10000);

  it('stays blocked after a denial where the Permissions API is missing, until Check again', async () => {
    permissions(null);
    const getCurrentPosition = geolocation(denied);
    const { result } = renderHook(() => useTimeClock(), { wrapper });
    await waitFor(() => expect(result.current.status.data).toBeDefined());

    act(() => result.current.punch());
    await waitFor(() => expect(result.current.locationBlocked).toBe(true));
    expect(getCurrentPosition).toHaveBeenCalledTimes(1);

    act(() => result.current.recheckLocation());
    expect(result.current.locationBlocked).toBe(false);
  });

  it('unblocks by itself when the browser permission flips to granted', async () => {
    const status = permissions('denied');
    geolocation(vi.fn());
    const { result } = renderHook(() => useTimeClock(), { wrapper });
    await waitFor(() => expect(result.current.locationBlocked).toBe(true));

    act(() => {
      status.state = 'granted';
      status.onchange?.();
    });

    await waitFor(() => expect(result.current.locationBlocked).toBe(false));
  });

  it('replaces the previous error toast instead of stacking a new one', async () => {
    permissions('prompt');
    geolocation(denied);
    const { result } = renderHook(() => useTimeClock(), { wrapper });
    await waitFor(() => expect(result.current.status.data).toBeDefined());

    act(() => result.current.punch());
    await waitFor(() => expect(toast.error).toHaveBeenCalled());

    expect(vi.mocked(toast.error).mock.calls[0][1]).toMatchObject({ id: 'time-clock' });
  });

  it('is off for an account with no linked employee when the caller requires one', () => {
    mockEmployee = null;
    const { result } = renderHook(() => useTimeClock({ requireEmployee: true }), { wrapper });

    expect(result.current.enabled).toBe(false);
    expect(punchApi.status).not.toHaveBeenCalled();
  });
});
