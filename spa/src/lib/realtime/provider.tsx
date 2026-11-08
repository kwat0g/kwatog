import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useSyncExternalStore,
  type ReactNode,
} from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { getEcho, resetEcho } from '@/lib/echo';
import {
  clearRealtimeSubscriptions,
  getRealtimeSubscriptionCount,
  retryRealtimeSubscriptions,
  subscribeRealtimeSubscriptions,
} from './subscriptions';
import { RealtimeContext } from './context';
import { catchUpRealtimeQueries } from './invalidationMap';
import {
  getRealtimeConnectionState,
  getRealtimeLastUpdatedAt,
  setRealtimeDeliveryState,
  setRealtimeLastUpdatedAt,
  setRealtimeTransportState,
  subscribeRealtimeStatus,
} from './status';
import { useAuthStore } from '@/stores/authStore';
import { realtimeIdentityKey } from './identity';

const HEALTHY_DELIVERY_HEALTH_INTERVAL_MS = 5_000;
const DEGRADED_DELIVERY_HEALTH_INTERVAL_MS = 2_000;

export type { RealtimeConnectionState } from './status';

interface PusherConnection {
  state: string;
  bind: (event: string, callback: (payload?: unknown) => void) => void;
  unbind: (event: string, callback: (payload?: unknown) => void) => void;
}

export function RealtimeProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const authenticated = user !== null;
  const subscriptionCount = useSyncExternalStore(
    subscribeRealtimeSubscriptions,
    getRealtimeSubscriptionCount,
    getRealtimeSubscriptionCount,
  );
  const realtimeEnabled = authenticated || subscriptionCount > 0;
  const key = useMemo(() => realtimeIdentityKey(user), [user]);
  const connection = useSyncExternalStore(
    subscribeRealtimeStatus,
    getRealtimeConnectionState,
    getRealtimeConnectionState,
  );
  const lastUpdatedAt = useSyncExternalStore(
    subscribeRealtimeStatus,
    getRealtimeLastUpdatedAt,
    getRealtimeLastUpdatedAt,
  );
  const catchUpInFlight = useRef<Promise<void> | null>(null);
  const catchUpRequested = useRef(false);

  const catchUp = useCallback(() => {
    if (catchUpInFlight.current) {
      // A second trigger may represent a commit that happened after the
      // current refetch began. Coalesce it into one trailing authorized read.
      catchUpRequested.current = true;
      return catchUpInFlight.current;
    }

    const pending = Promise.resolve().then(async () => {
      try {
        do {
          catchUpRequested.current = false;
          await catchUpRealtimeQueries(queryClient);
        } while (catchUpRequested.current);
      } finally {
        if (catchUpInFlight.current === pending) catchUpInFlight.current = null;
      }
    });
    catchUpInFlight.current = pending;

    return pending;
  }, [queryClient]);

  // Echo being connected only proves the socket is open. It does not prove
  // the durable outbox and broadcast workers are draining their queues.
  // This endpoint returns a single authenticated health bit, never records.
  useEffect(() => {
    if (!realtimeEnabled) {
      setRealtimeDeliveryState('unknown');
      return;
    }

    let disposed = false;
    let inFlight = false;
    let hasObservedDelivery = false;
    let wasDegraded = false;
    let timer: number | undefined;
    let requestTimeout: number | undefined;

    const checkDelivery = async () => {
      if (disposed || inFlight || document.visibilityState !== 'visible') return;
      inFlight = true;
      const abortController = new AbortController();
      requestTimeout = window.setTimeout(() => abortController.abort(), 2_000);
      try {
        const response = await fetch('/api/v1/realtime/health', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
          signal: abortController.signal,
        });
        if (!response.ok) throw new Error(`Delivery health returned HTTP ${response.status}.`);
        const body = await response.json() as { data?: { delivery?: string } };
        if (disposed) return;
        const healthy = body.data?.delivery === 'healthy';
        setRealtimeDeliveryState(healthy ? 'healthy' : 'degraded');
        // The first check happens during mount, when each active realtime
        // subscription is already doing its own authorized catch-up after
        // channel authorization. Avoid duplicating all page queries globally.
        // Later delivery-state transitions need one global catch-up so a
        // temporary queue outage cannot leave active data behind.
        if (hasObservedDelivery && healthy && wasDegraded) void catchUp();
        if (hasObservedDelivery && !healthy && !wasDegraded) void catchUp();
        wasDegraded = !healthy;
        hasObservedDelivery = true;
      } catch {
        if (disposed) return;
        setRealtimeDeliveryState('degraded');
        if (hasObservedDelivery && !wasDegraded) void catchUp();
        wasDegraded = true;
        hasObservedDelivery = true;
      } finally {
        if (requestTimeout !== undefined) window.clearTimeout(requestTimeout);
        inFlight = false;
      }
    };

    const schedule = () => {
      if (disposed) return;
      timer = window.setTimeout(() => {
        void checkDelivery().finally(schedule);
      }, wasDegraded ? DEGRADED_DELIVERY_HEALTH_INTERVAL_MS : HEALTHY_DELIVERY_HEALTH_INTERVAL_MS);
    };
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') void checkDelivery();
    };

    void checkDelivery().finally(schedule);
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      disposed = true;
      if (timer !== undefined) window.clearTimeout(timer);
      if (requestTimeout !== undefined) window.clearTimeout(requestTimeout);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      setRealtimeDeliveryState('unknown');
    };
  }, [realtimeEnabled, catchUp, key]);

  useEffect(() => {
    if (!realtimeEnabled || connection === 'live') return;

    let delay = 5_000;
    let timer: number | undefined;
    const schedule = () => {
      timer = window.setTimeout(() => {
        if (document.visibilityState === 'visible') void catchUp();
        delay = Math.min(delay * 2, 60_000);
        schedule();
      }, delay);
    };

    schedule();
    return () => {
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [realtimeEnabled, catchUp, connection]);

  useEffect(() => {
    let disposed = false;
    let hasConnected = false;
    let connecting = false;
    let initializationRetryDelay = 1_000;
    let initializationRetryTimer: number | undefined;
    let pusherConnection: PusherConnection | undefined;

    // A separate portal guard (supplier/customer) can own private listeners
    // while the internal ERP auth store is empty. Respect those subscribers.
    if (!authenticated && getRealtimeSubscriptionCount() === 0) {
      setRealtimeTransportState('offline');
      setRealtimeLastUpdatedAt(null);
      clearRealtimeSubscriptions();
      resetEcho();
      return;
    }

    setRealtimeTransportState('reconnecting');
    setRealtimeLastUpdatedAt(null);

    const onStateChange = (payload?: unknown) => {
      const current = (payload as { current?: string } | undefined)?.current;
      if (current === 'connected') {
        setRealtimeTransportState('live');
        if (hasConnected) {
          retryRealtimeSubscriptions();
          void catchUp();
        }
        hasConnected = true;
      } else if (current === 'connecting' || current === 'initialized') {
        setRealtimeTransportState('reconnecting');
      } else {
        setRealtimeTransportState('offline');
      }
    };

    const retryEchoInitialization = () => {
      if (disposed || connecting || pusherConnection) return;
      if (initializationRetryTimer !== undefined) {
        window.clearTimeout(initializationRetryTimer);
        initializationRetryTimer = undefined;
      }
      connecting = true;
      void getEcho()
        .then((echo) => {
          if (disposed) return;
          pusherConnection = (echo as unknown as { connector: { pusher: { connection: PusherConnection } } })
            .connector.pusher.connection;
          pusherConnection.bind('state_change', onStateChange);
          if (pusherConnection.state === 'connected') onStateChange({ current: 'connected' });
          else setRealtimeTransportState(pusherConnection.state === 'connecting' ? 'reconnecting' : 'offline');
          retryRealtimeSubscriptions();
          initializationRetryDelay = 1_000;
        })
        .catch((error: unknown) => {
          if (disposed) return;
          pusherConnection = undefined;
          setRealtimeTransportState('offline');
          console.error('Real-time connection could not be initialized.', error);
          const delay = initializationRetryDelay;
          initializationRetryDelay = Math.min(initializationRetryDelay * 2, 30_000);
          initializationRetryTimer = window.setTimeout(() => {
            initializationRetryTimer = undefined;
            retryEchoInitialization();
          }, delay);
        })
        .finally(() => {
          connecting = false;
        });
    };
    const retryEchoInitializationNow = () => {
      if (pusherConnection) {
        retryRealtimeSubscriptions();
        return;
      }
      if (connecting) return;
      if (initializationRetryTimer !== undefined) {
        window.clearTimeout(initializationRetryTimer);
        initializationRetryTimer = undefined;
      }
      retryEchoInitialization();
    };
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible' && document.hasFocus()) {
        void catchUp();
        retryEchoInitializationNow();
      }
    };
    const onWindowFocus = () => {
      if (document.visibilityState === 'visible') {
        void catchUp();
        retryEchoInitializationNow();
      }
    };
    const onBrowserOnline = () => {
      if (pusherConnection?.state === 'connected') setRealtimeTransportState('live');
      else setRealtimeTransportState('reconnecting');
      retryEchoInitializationNow();
      // HTTP can recover while the socket still appears connected (or while
      // Reverb is reconnecting). Revalidate active authorized queries instead
      // of trusting the in-memory cache after the browser comes back online.
      void catchUp();
    };
    const onBrowserOffline = () => {
      // If the socket itself remains connected, preserve its confirmed private
      // channel readiness. If it disconnects, its state-change callback uses
      // the default behavior and marks every channel pending for reauth.
      setRealtimeTransportState('offline', { resetChannels: pusherConnection?.state !== 'connected' });
    };

    retryEchoInitialization();

    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('focus', onWindowFocus);
    window.addEventListener('online', onBrowserOnline);
    window.addEventListener('offline', onBrowserOffline);

    return () => {
      disposed = true;
      if (pusherConnection) pusherConnection.unbind('state_change', onStateChange);
      if (initializationRetryTimer !== undefined) window.clearTimeout(initializationRetryTimer);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('focus', onWindowFocus);
      window.removeEventListener('online', onBrowserOnline);
      window.removeEventListener('offline', onBrowserOffline);
      setRealtimeTransportState('offline');
      clearRealtimeSubscriptions();
      resetEcho();
    };
  }, [authenticated, catchUp, key, realtimeEnabled]);

  return <RealtimeContext.Provider value={{ state: connection, lastUpdatedAt }}>{children}</RealtimeContext.Provider>;
}
