import { useEffect, useState } from 'react';
import { Spinner } from './Spinner';
import { cn } from '@/lib/cn';

/** Seconds since `active` last became true; 0 while inactive. */
export function useElapsedSeconds(active: boolean): number {
  const [seconds, setSeconds] = useState(0);
  useEffect(() => {
    if (!active) {
      setSeconds(0);
      return;
    }
    const startedAt = Date.now();
    const id = window.setInterval(
      () => setSeconds(Math.floor((Date.now() - startedAt) / 1000)),
      1000,
    );
    return () => window.clearInterval(id);
  }, [active]);
  return seconds;
}

interface Props {
  /** True while the operation is in flight. */
  active: boolean;
  /**
   * Roughly how long this normally takes, in seconds. Pass only a figure you
   * actually know — a made-up estimate is worse than none, because the user
   * calibrates on it and then distrusts every estimate in the app.
   */
  expectedSeconds?: number;
  /** What is running, lower case: "depreciation posting", "the import". */
  label: string;
  className?: string;
}

/**
 * Elapsed-time and expectation line for an operation whose duration the user
 * cannot predict.
 *
 * Every long operation in the app except the payroll compute showed a bare
 * spinner and the word "Running…", so a depreciation post over 400 assets
 * looked identical to a hung request. There is no progress to report for these
 * — the endpoints are synchronous and return once — so this reports the two
 * things that are actually known: how long it has been going, and whether that
 * is longer than it should be.
 *
 * Where the server does report progress, use a real bar instead. See
 * `components/payroll/PayrollComputeProgressPanel`.
 */
export function PendingHint({ active, expectedSeconds, label, className }: Props) {
  const elapsed = useElapsedSeconds(active);
  if (!active) return null;

  const overdue = expectedSeconds !== undefined && elapsed > expectedSeconds * 2;
  // Below ~3s the line flickers in and out and reads as a glitch; the button's
  // own loading state already covers that window.
  if (elapsed < 3) return null;

  return (
    <p
      role="status"
      aria-live="polite"
      className={cn(
        'flex items-center gap-2 text-xs',
        overdue ? 'text-warning-fg' : 'text-muted',
        className,
      )}
    >
      <Spinner size="sm" className={overdue ? 'text-warning-fg' : 'text-muted'} />
      <span>
        Still running {label} — <span className="font-mono tabular-nums">{elapsed}s</span> elapsed
        {expectedSeconds !== undefined && !overdue && (
          <> · usually takes about <span className="font-mono tabular-nums">{expectedSeconds}s</span></>
        )}
        {overdue && <> · longer than usual. Leave this open; the work is still in progress.</>}
      </span>
    </p>
  );
}
