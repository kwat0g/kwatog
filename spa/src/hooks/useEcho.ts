import { useEffect } from 'react';
import { getEcho } from '@/lib/echo';

/**
 * Sprint 6 — Task 55 helper hook. Subscribes to a private channel for the
 * lifetime of the component, calls `handler` whenever `event` fires, and
 * tears down on unmount.
 *
 * Reverb broadcast event names match what the server sets via broadcastAs():
 * - 'output.recorded' (WorkOrderOutputRecorded)
 * - 'machine.status_changed' (MachineStatusChanged)
 */
export function useEcho<T = unknown>(
  channel: string,
  event: string,
  handler: (payload: T) => void,
): void {
  useEffect(() => {
    // Echo loads on demand (see `@/lib/echo`), so subscribe once it resolves
    // and skip entirely if we unmounted while the import was in flight.
    let disposed = false;
    let teardown: (() => void) | undefined;

    void getEcho().then((echo) => {
      if (disposed) return;
      const sub = echo.private(channel);
      sub.listen(event, handler as (e: unknown) => void);
      teardown = () => {
        try {
          sub.stopListening(event);
        } catch {
          // ignore: channel was already gone (e.g. in HMR teardown)
        }
        echo.leave(channel);
      };
    });

    return () => {
      disposed = true;
      teardown?.();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel, event]);
}
