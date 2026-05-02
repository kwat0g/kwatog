import { useEffect } from 'react';
import { echo } from '@/lib/echo';

/**
 * Sprint 6 — Task 55 helper hook. Subscribes to a private channel for the
 * lifetime of the component, calls `handler` whenever `event` fires, and
 * tears down on unmount.
 *
 * Reverb broadcast event names match what the server sets via broadcastAs():
 *   - 'output.recorded'           (WorkOrderOutputRecorded)
 *   - 'machine.status_changed'    (MachineStatusChanged)
 */
export function useEcho<T = unknown>(
  channel: string,
  event: string,
  handler: (payload: T) => void,
): void {
  useEffect(() => {
    const sub = echo.private(channel);
    sub.listen(event, handler as (e: unknown) => void);
    return () => {
      try {
        sub.stopListening(event);
      } catch {
        // ignore: channel was already gone (e.g. in HMR teardown)
      }
      echo.leave(channel);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel, event]);
}
