import { useRealtimeEvent } from './useRealtimeEvent';

/** Backwards-compatible wrapper around the shared, ref-counted realtime layer. */
export function useEcho<T = unknown>(
  channel: string,
  event: string,
  handler: (payload: T) => void,
  onSubscribed?: () => void,
  onError?: (error: unknown) => void,
): void {
  useRealtimeEvent(channel, event, handler, onSubscribed, onError);
}
