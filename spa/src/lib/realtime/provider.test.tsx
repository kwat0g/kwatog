import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useAuthStore } from '@/stores/authStore';
import { resetRealtimeStatus, setRealtimeChannelState } from './status';

const { getEchoMock, resetEchoMock, clearSubscriptionsMock, retrySubscriptionsMock, subscriptionCountMock, subscribeSubscriptionsMock, bindMock, unbindMock, healthFetchMock } = vi.hoisted(() => ({
  getEchoMock: vi.fn(),
  resetEchoMock: vi.fn(),
  clearSubscriptionsMock: vi.fn(),
  retrySubscriptionsMock: vi.fn(),
  subscriptionCountMock: vi.fn(() => 0),
  subscribeSubscriptionsMock: vi.fn(() => () => undefined),
  bindMock: vi.fn(),
  unbindMock: vi.fn(),
  healthFetchMock: vi.fn(),
}));

vi.mock('@/lib/echo', () => ({ getEcho: getEchoMock, resetEcho: resetEchoMock }));
vi.mock('./subscriptions', () => ({
  clearRealtimeSubscriptions: clearSubscriptionsMock,
  getRealtimeSubscriptionCount: subscriptionCountMock,
  retryRealtimeSubscriptions: retrySubscriptionsMock,
  subscribeRealtimeSubscriptions: subscribeSubscriptionsMock,
}));

import { RealtimeProvider } from './provider';
import { useRealtimeStatus } from './context';

const user = {
  id: 'user-1',
  role: { id: 'role-1', slug: 'system_admin' },
  permissions: ['dashboard.view'],
  features: ['dashboard'],
} as NonNullable<ReturnType<typeof useAuthStore.getState>['user']>;

function StatusProbe() {
  const { state } = useRealtimeStatus();
  return <output>{state}</output>;
}

function healthResponse(delivery: string): Response {
  return {
    ok: true,
    json: async () => ({ data: { delivery } }),
  } as Response;
}

describe('RealtimeProvider', () => {
  let client: QueryClient;

  beforeEach(() => {
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    vi.stubGlobal('fetch', healthFetchMock);
    useAuthStore.setState({ user });
    healthFetchMock.mockResolvedValue(healthResponse('healthy'));
    getEchoMock.mockResolvedValue({
      connector: {
        pusher: {
          connection: {
            state: 'connected',
            bind: (event: string, callback: (payload?: unknown) => void) => {
              bindMock(event, callback);
            },
            unbind: unbindMock,
          },
        },
      },
    });
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    client.clear();
    useAuthStore.setState({ user: null });
    resetRealtimeStatus();
    vi.clearAllMocks();
  });

  it('skips a redundant initial-connect catch-up and coalesces focus and visibility catch-up', async () => {
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    vi.spyOn(document, 'hasFocus').mockReturnValue(true);
    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(document.querySelector('output')?.textContent).toBe('live'));
    await waitFor(() => expect(healthFetchMock).toHaveBeenCalledTimes(1));
    expect(invalidate).not.toHaveBeenCalled();
    act(() => {
      window.dispatchEvent(new Event('focus'));
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await waitFor(() => expect(invalidate).toHaveBeenCalledTimes(1));
    expect(invalidate).toHaveBeenCalledWith({ refetchType: 'active' }, { cancelRefetch: false });

    expect(bindMock).toHaveBeenCalledWith('state_change', expect.any(Function));
  });

  it('catches active data up after a real socket reconnect, not the initial connection', async () => {
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();
    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(document.querySelector('output')?.textContent).toBe('live'));
    const onStateChange = bindMock.mock.calls[0]?.[1] as ((payload: { current: string }) => void) | undefined;
    expect(onStateChange).toBeTypeOf('function');
    expect(invalidate).not.toHaveBeenCalled();

    act(() => {
      onStateChange?.({ current: 'unavailable' });
      onStateChange?.({ current: 'connected' });
    });

    await waitFor(() => expect(invalidate).toHaveBeenCalledTimes(1));
  });

  it('detects a stalled broadcast lane while the socket remains connected', async () => {
    vi.useFakeTimers();
    healthFetchMock
      .mockResolvedValueOnce(healthResponse('healthy'))
      .mockResolvedValueOnce(healthResponse('degraded'));

    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(document.querySelector('output')?.textContent).toBe('live');
    expect(healthFetchMock).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(4_999);
    });
    expect(document.querySelector('output')?.textContent).toBe('live');
    expect(healthFetchMock).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(1);
    });
    expect(document.querySelector('output')?.textContent).toBe('reconnecting');
    expect(healthFetchMock).toHaveBeenCalledTimes(2);
  });

  it('retries Echo initialization after a transient client-load failure', async () => {
    vi.useFakeTimers();
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    getEchoMock
      .mockRejectedValueOnce(new Error('temporary Echo load failure'))
      .mockResolvedValue({
        connector: {
          pusher: {
            connection: {
              state: 'connected',
              bind: (event: string, callback: (payload?: unknown) => void) => {
                bindMock(event, callback);
              },
              unbind: unbindMock,
            },
          },
        },
      });

    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(getEchoMock).toHaveBeenCalledTimes(1);
    expect(document.querySelector('output')?.textContent).toBe('offline');

    await act(async () => {
      await vi.advanceTimersByTimeAsync(999);
    });
    expect(getEchoMock).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(1);
    });

    expect(getEchoMock).toHaveBeenCalledTimes(2);
    expect(document.querySelector('output')?.textContent).toBe('live');
    expect(retrySubscriptionsMock).toHaveBeenCalled();
    errorSpy.mockRestore();
  });

  it('shows reconnecting and enables the degraded-mode state while a private channel has failed', async () => {
    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(document.querySelector('output')?.textContent).toBe('live'));
    act(() => setRealtimeChannelState('user.viewer-1', 'failed'));
    await waitFor(() => expect(document.querySelector('output')?.textContent).toBe('reconnecting'));

    act(() => setRealtimeChannelState('user.viewer-1', 'ready'));
    await waitFor(() => expect(document.querySelector('output')?.textContent).toBe('live'));
  });

  it('uses the visibility-aware fallback instead of an immediate global catch-up on initial degradation', async () => {
    vi.useFakeTimers();
    healthFetchMock.mockResolvedValue(healthResponse('degraded'));
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(document.querySelector('output')?.textContent).toBe('reconnecting');
    expect(healthFetchMock).toHaveBeenCalledWith('/api/v1/realtime/health', expect.objectContaining({
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
      signal: expect.any(AbortSignal),
    }));
    expect(invalidate).not.toHaveBeenCalled();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(4_999);
    });
    expect(invalidate).not.toHaveBeenCalled();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1);
    });
    expect(invalidate).toHaveBeenCalledTimes(1);
    expect(bindMock).toHaveBeenCalledWith('state_change', expect.any(Function));
  });

  it('backs off catch-up while delivery is degraded and refreshes once delivery recovers', async () => {
    vi.useFakeTimers();
    healthFetchMock
      .mockResolvedValueOnce(healthResponse('degraded'))
      .mockResolvedValueOnce(healthResponse('degraded'))
      .mockResolvedValueOnce(healthResponse('degraded'))
      .mockResolvedValue(healthResponse('healthy'));
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(document.querySelector('output')?.textContent).toBe('reconnecting');
    const initialHealthChecks = healthFetchMock.mock.calls.length;
    expect(initialHealthChecks).toBeGreaterThanOrEqual(1);
    expect(invalidate).not.toHaveBeenCalled();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(4_999);
    });
    expect(invalidate).not.toHaveBeenCalled();
    expect(healthFetchMock).toHaveBeenCalledTimes(initialHealthChecks + 2);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1);
    });
    expect(healthFetchMock).toHaveBeenCalledTimes(initialHealthChecks + 2);
    expect(invalidate).toHaveBeenCalledTimes(1);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_000);
    });
    expect(healthFetchMock).toHaveBeenCalledTimes(initialHealthChecks + 3);
    expect(invalidate).toHaveBeenCalledTimes(2);
    expect(healthFetchMock).toHaveBeenCalledWith('/api/v1/realtime/health', expect.objectContaining({
      credentials: 'same-origin',
      cache: 'no-store',
      signal: expect.any(AbortSignal),
    }));
  });

  it('catches active queries up with backoff while the realtime transport is offline', async () => {
    vi.useFakeTimers();
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();
    getEchoMock.mockResolvedValue({
      connector: {
        pusher: {
          connection: {
            state: 'unavailable',
            bind: bindMock,
            unbind: unbindMock,
          },
        },
      },
    });

    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await act(async () => {
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(document.querySelector('output')?.textContent).toBe('offline');
    expect(invalidate).not.toHaveBeenCalled();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(5_000);
    });

    expect(invalidate).toHaveBeenCalledTimes(1);
    expect(invalidate).toHaveBeenCalledWith({ refetchType: 'active' }, { cancelRefetch: false });
  });

  it('keeps Echo available for a supplier portal subscriber without an internal ERP user', async () => {
    useAuthStore.setState({ user: null });
    subscriptionCountMock.mockReturnValue(1);
    healthFetchMock.mockResolvedValue({ ok: false, status: 401 } as Response);

    render(
      <QueryClientProvider client={client}>
        <RealtimeProvider>
          <StatusProbe />
        </RealtimeProvider>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(bindMock).toHaveBeenCalledWith('state_change', expect.any(Function)));
    await waitFor(() => expect(document.querySelector('output')?.textContent).toBe('reconnecting'));
    expect(clearSubscriptionsMock).not.toHaveBeenCalled();
    expect(resetEchoMock).not.toHaveBeenCalled();
    expect(window.location.pathname).not.toBe('/sign-in');
    expect(healthFetchMock).toHaveBeenCalledWith('/api/v1/realtime/health', expect.objectContaining({
      credentials: 'same-origin',
      cache: 'no-store',
    }));
  });
});
