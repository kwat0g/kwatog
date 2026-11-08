import { act, renderHook, waitFor } from '@testing-library/react';
import type { AuthUser } from '@/api/auth';
import { afterEach, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { clearRealtimeSubscriptions } from '@/lib/realtime/subscriptions';
import { useEcho } from './useEcho';
import { useRealtimeEvent } from './useRealtimeEvent';

const echoMocks = vi.hoisted(() => {
  const handlers: Record<string, (payload: unknown) => void> = {};
  const subscribedHandlers: Array<() => void> = [];
  const errorHandlers: Array<(error: unknown) => void> = [];
  const channel = {
    listen: vi.fn((event: string, handler: (payload: unknown) => void) => {
      handlers[event] = handler;
      return channel;
    }),
    stopListening: vi.fn(() => channel),
    subscribed: vi.fn((handler: () => void) => {
      subscribedHandlers.push(handler);
      return channel;
    }),
    error: vi.fn((handler: (error: unknown) => void) => {
      errorHandlers.push(handler);
      return channel;
    }),
  };
  const echo = { private: vi.fn(() => channel), leave: vi.fn() };
  return { handlers, subscribedHandlers, errorHandlers, channel, echo };
});

vi.mock('@/lib/echo', () => ({ getEcho: async () => echoMocks.echo }));

afterEach(() => {
  clearRealtimeSubscriptions();
  useAuthStore.setState({ user: null });
  Object.keys(echoMocks.handlers).forEach((event) => delete echoMocks.handlers[event]);
  echoMocks.subscribedHandlers.length = 0;
  echoMocks.errorHandlers.length = 0;
  vi.clearAllMocks();
});

it('keeps the subscription lifecycle safe for a partially hydrated identity', async () => {
  useAuthStore.setState({ user: { id: 'legacy-user' } as AuthUser });
  const handler = vi.fn();

  const { unmount } = renderHook(() => useRealtimeEvent('entity.machine.visible', '.chain.refresh', handler));
  await waitFor(() => expect(echoMocks.channel.listen).toHaveBeenCalledWith('.chain.refresh', expect.any(Function)));

  await act(async () => echoMocks.handlers['.chain.refresh']?.({ refresh: true }));
  expect(handler).toHaveBeenCalledWith({ refresh: true });
  unmount();
});

it('forwards private-channel readiness and failure through the legacy wrapper', async () => {
  act(() => useAuthStore.setState({ user: { id: 'payroll-viewer' } as AuthUser }));
  const handler = vi.fn();
  const onSubscribed = vi.fn();
  const onError = vi.fn();

  const { unmount } = renderHook(() => useEcho(
    'payroll.period.42',
    '.payroll.progress',
    handler,
    onSubscribed,
    onError,
  ));

  await waitFor(() => {
    expect(echoMocks.channel.listen).toHaveBeenCalledWith('.payroll.progress', expect.any(Function));
    expect(echoMocks.channel.subscribed).toHaveBeenCalledWith(expect.any(Function));
    expect(echoMocks.channel.error).toHaveBeenCalledWith(expect.any(Function));
  });

  await act(async () => echoMocks.subscribedHandlers[0]?.());
  expect(onSubscribed).toHaveBeenCalledOnce();

  const failure = { status: 403 };
  await act(async () => echoMocks.errorHandlers[0]?.(failure));
  expect(onError).toHaveBeenCalledWith(failure);
  unmount();
});
