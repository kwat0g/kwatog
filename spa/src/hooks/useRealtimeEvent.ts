import { useEffect, useRef } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { subscribeRealtimeEvent } from '@/lib/realtime/subscriptions';
import { realtimeIdentityKey } from '@/lib/realtime/identity';

/** Shared, reference-counted private-channel subscription for React consumers. */
export function useRealtimeEvent<T = unknown>(
  channel: string,
  event: string,
  handler: (payload: T) => void,
  onSubscribed?: () => void,
  onError?: (error: unknown) => void,
): void {
  const user = useAuthStore((state) => state.user);
  const identity = realtimeIdentityKey(user);
  const currentHandler = useRef(handler);
  const currentOnSubscribed = useRef(onSubscribed);
  const currentOnError = useRef(onError);

  useEffect(() => {
    currentHandler.current = handler;
  }, [handler]);

  useEffect(() => {
    currentOnSubscribed.current = onSubscribed;
  }, [onSubscribed]);

  useEffect(() => {
    currentOnError.current = onError;
  }, [onError]);

  useEffect(() => {
    if (!channel) return;
    return subscribeRealtimeEvent<T>(
      channel,
      event,
      (payload) => currentHandler.current(payload),
      () => currentOnSubscribed.current?.(),
      (error) => currentOnError.current?.(error),
    );
  }, [channel, event, identity]);
}
