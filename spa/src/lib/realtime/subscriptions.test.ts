import { afterEach, describe, expect, it, vi } from 'vitest';

const echoMocks = vi.hoisted(() => {
  const callbacks = new Map<string, (payload: unknown) => void>();
  const subscriptionCallbacks: Array<() => void> = [];
  const errorCallbacks: Array<(error: unknown) => void> = [];
  const channel = {
    listen: vi.fn((event: string, handler: (payload: unknown) => void) => {
      callbacks.set(event, handler);
      return channel;
    }),
    stopListening: vi.fn((event: string) => {
      callbacks.delete(event);
      return channel;
    }),
    subscribed: vi.fn((handler: () => void) => {
      subscriptionCallbacks.push(handler);
      return channel;
    }),
    error: vi.fn((handler: (error: unknown) => void) => {
      errorCallbacks.push(handler);
      return channel;
    }),
  };
  const echo = {
    private: vi.fn(() => channel),
    leave: vi.fn(),
  };
  const getEcho = vi.fn(async () => echo);
  return { callbacks, channel, echo, getEcho, subscriptionCallbacks, errorCallbacks };
});

vi.mock('@/lib/echo', () => ({ getEcho: echoMocks.getEcho }));

import { clearRealtimeSubscriptions, subscribeRealtimeEvent } from './subscriptions';
import {
  getRealtimeConnectionState,
  resetRealtimeStatus,
  setRealtimeDeliveryState,
  setRealtimeTransportState,
} from './status';

afterEach(() => {
  clearRealtimeSubscriptions();
  resetRealtimeStatus();
  echoMocks.callbacks.clear();
  echoMocks.subscriptionCallbacks.length = 0;
  echoMocks.errorCallbacks.length = 0;
  echoMocks.channel.listen.mockClear();
  echoMocks.channel.stopListening.mockClear();
  echoMocks.channel.subscribed.mockClear();
  echoMocks.channel.error.mockClear();
  echoMocks.echo.private.mockClear();
  echoMocks.echo.leave.mockClear();
  echoMocks.getEcho.mockReset().mockResolvedValue(echoMocks.echo);
});

describe('realtime subscriptions', () => {
  it('shares one private subscription and fans an event out to mounted consumers', async () => {
    const first = vi.fn();
    const second = vi.fn();
    const stopFirst = subscribeRealtimeEvent('chain.purchase_request.one', '.chain.refresh', first);
    const stopSecond = subscribeRealtimeEvent('chain.purchase_request.one', '.chain.refresh', second);

    await vi.waitFor(() => expect(echoMocks.channel.listen).toHaveBeenCalledTimes(1));
    expect(echoMocks.echo.private).toHaveBeenCalledTimes(1);

    echoMocks.callbacks.get('.chain.refresh')?.({ refresh: true });
    expect(first).toHaveBeenCalledWith({ refresh: true });
    expect(second).toHaveBeenCalledWith({ refresh: true });

    stopFirst();
    expect(echoMocks.channel.stopListening).not.toHaveBeenCalled();
    stopSecond();
    expect(echoMocks.channel.stopListening).toHaveBeenCalledTimes(1);
    expect(echoMocks.echo.leave).toHaveBeenCalledWith('chain.purchase_request.one');
  });

  it('does not install a channel when the only subscriber unmounts before Echo loads', async () => {
    const stop = subscribeRealtimeEvent('chain.work_order.one', '.chain.refresh', vi.fn());
    stop();

    await Promise.resolve();
    await Promise.resolve();
    expect(echoMocks.echo.private).not.toHaveBeenCalled();
  });

  it('reattaches active channel registrations after a transient Echo load failure', async () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const onError = vi.fn();
    echoMocks.getEcho
      .mockRejectedValueOnce(new Error('temporary Echo load failure'))
      .mockResolvedValue(echoMocks.echo);

    subscribeRealtimeEvent('entity.purchase_order.visible', '.chain.refresh', vi.fn(), undefined, onError);
    await vi.waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
    expect(echoMocks.echo.private).not.toHaveBeenCalled();

    const { retryRealtimeSubscriptions } = await import('./subscriptions');
    expect(retryRealtimeSubscriptions).toBeTypeOf('function');
    retryRealtimeSubscriptions();

    await vi.waitFor(() => expect(echoMocks.echo.private).toHaveBeenCalledWith('entity.purchase_order.visible'));
    expect(echoMocks.channel.listen).toHaveBeenCalledWith('.chain.refresh', expect.any(Function));
    errorSpy.mockRestore();
  });

  it('reports initial subscription and reconnection so active data can catch up', async () => {
    const firstReady = vi.fn();
    const secondReady = vi.fn();
    subscribeRealtimeEvent('entity.purchase_order.visible', '.chain.refresh', vi.fn(), firstReady);
    subscribeRealtimeEvent('entity.purchase_order.visible', '.chain.refresh', vi.fn(), secondReady);

    await vi.waitFor(() => expect(echoMocks.channel.listen).toHaveBeenCalledTimes(1));
    expect(firstReady).not.toHaveBeenCalled();
    expect(secondReady).not.toHaveBeenCalled();

    echoMocks.subscriptionCallbacks[0]?.();
    expect(firstReady).toHaveBeenCalledTimes(1);
    expect(secondReady).toHaveBeenCalledTimes(1);

    // Pusher reports subscription_succeeded again after reconnect.
    echoMocks.subscriptionCallbacks[0]?.();
    expect(firstReady).toHaveBeenCalledTimes(2);
    expect(secondReady).toHaveBeenCalledTimes(2);
  });

  it('registers a handler for private-channel authorization errors', async () => {
    const onError = vi.fn();
    setRealtimeDeliveryState('healthy');
    setRealtimeTransportState('live');
    const stop = subscribeRealtimeEvent('entity.purchase_order.visible', '.chain.refresh', vi.fn(), undefined, onError);
    await vi.waitFor(() => expect(echoMocks.channel.listen).toHaveBeenCalledTimes(1));

    expect(echoMocks.channel.error).toHaveBeenCalledTimes(1);
    echoMocks.errorCallbacks[0]?.({ status: 403 });
    expect(onError).toHaveBeenCalledWith({ status: 403 });
    expect(getRealtimeConnectionState()).toBe('reconnecting');

    echoMocks.subscriptionCallbacks[0]?.();
    expect(getRealtimeConnectionState()).toBe('live');
    stop();
  });

  it('reopens an active private channel after its authorization fails', async () => {
    const channelName = 'entity.purchase_order.visible';
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const stop = subscribeRealtimeEvent(channelName, '.chain.refresh', vi.fn());
    await vi.waitFor(() => expect(echoMocks.channel.listen).toHaveBeenCalledTimes(1));

    echoMocks.errorCallbacks[0]?.({ type: 'AuthError', status: 503 });
    const { retryRealtimeSubscriptions } = await import('./subscriptions');
    retryRealtimeSubscriptions();

    await vi.waitFor(() => expect(echoMocks.echo.private).toHaveBeenCalledTimes(2));
    expect(echoMocks.echo.leave).toHaveBeenCalledWith(channelName);
    expect(echoMocks.channel.listen).toHaveBeenCalledTimes(2);

    stop();
    errorSpy.mockRestore();
  });
});
