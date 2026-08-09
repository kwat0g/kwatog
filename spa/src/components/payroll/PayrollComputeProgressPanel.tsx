import { AlertTriangle, LockOpen, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { Spinner } from '@/components/ui/Spinner';
import { formatRelative } from '@/lib/formatDate';
import type { PayrollComputeProgress } from '@/types/payroll';

interface Props {
 progress: PayrollComputeProgress | null | undefined;
 startedAt: string | null | undefined;
 /** Claim is older than the stale threshold — worker presumed dead. */
 isStale: boolean;
 /** Retry takes over the dead claim (needs payroll.periods.compute). */
 canRetry: boolean;
 onRetry: () => void;
 retryPending: boolean;
 /** Manual override for admins holding payroll.periods.force_unlock. */
 canForceUnlock: boolean;
 onForceUnlock: () => void;
 forceUnlockPending: boolean;
}

/**
 * Live feedback while ProcessPayrollJob iterates employees.
 *
 * Previously this state showed only a bare "Computing…" spinner, so a
 * 200-employee run looked identical to a hung one and users could not tell
 * whether anything was happening. Now it shows counts, a percentage bar and
 * elapsed time, and when the claim goes stale it says so and offers a way out
 * that does not require the force-unlock permission.
 */
export function PayrollComputeProgressPanel({
 progress,
 startedAt,
 isStale,
 canRetry,
 onRetry,
 retryPending,
 canForceUnlock,
 onForceUnlock,
 forceUnlockPending,
}: Props) {
 if (isStale) {
 return (
 <div className="mb-4 rounded-md border border-warning bg-warning-bg px-3 py-2.5 text-warning-fg">
 <div className="flex items-start gap-2">
 <AlertTriangle size={14} className="mt-0.5 shrink-0" />
 <div className="min-w-0 flex-1">
 <div className="text-xs font-medium">This compute run has stalled</div>
 <p className="mt-0.5 text-xs text-primary/80">
 It was claimed {formatRelative(startedAt)} and its worker has not reported since —
 usually a restarted or out-of-memory queue worker. No payroll data was lost.
 {canRetry && ' Re-running Compute takes over the dead run and starts fresh.'}
 </p>
 {progress && progress.total > 0 && (
 <p className="mt-1 font-mono text-2xs tabular-nums text-primary/70">
 Last reported {progress.processed} / {progress.total} employees
 </p>
 )}
 <div className="mt-2 flex items-center gap-2">
 {canRetry && (
 <Button
 variant="secondary"
 size="sm"
 icon={<RefreshCw size={14} />}
 onClick={onRetry}
 disabled={retryPending}
 loading={retryPending}
 >
 Retry compute
 </Button>
 )}
 {canForceUnlock && (
 <Button
 variant="ghost"
 size="sm"
 icon={<LockOpen size={14} />}
 onClick={onForceUnlock}
 disabled={forceUnlockPending}
 loading={forceUnlockPending}
 >
 Force unlock
 </Button>
 )}
 </div>
 </div>
 </div>
 </div>
 );
 }

 const total = progress?.total ?? 0;
 const processed = progress?.processed ?? 0;
 const failures = progress?.failures ?? 0;
 // Before the first snapshot lands we know a run is claimed but not its size,
 // so show an indeterminate spinner rather than a misleading 0%.
 const hasCounts = total > 0;

 return (
 <div className="mb-4 rounded-md border border-default bg-surface px-3 py-2.5">
 <div className="flex items-center justify-between gap-3">
 <span className="flex items-center gap-2 text-xs font-medium text-primary">
 <Spinner size="sm" />
 Computing payroll…
 </span>
 {hasCounts && (
 <span className="font-mono text-xs tabular-nums text-muted">
 {progress?.percent ?? 0}%
 </span>
 )}
 </div>

 {hasCounts ? (
 <>
 <ProgressBar
 value={progress?.percent ?? 0}
 variant={failures > 0 ? 'warning' : 'accent'}
 className="mt-2"
 ariaLabel="Payroll computation progress"
 />
 <div className="mt-1.5 flex items-center justify-between gap-3 text-2xs text-muted">
 <span className="font-mono tabular-nums">
 {processed} / {total} employees
 {failures > 0 && (
 <span className="text-danger-fg"> · {failures} failed</span>
 )}
 </span>
 {startedAt && <span>Started {formatRelative(startedAt)}</span>}
 </div>
 </>
 ) : (
 <p className="mt-1 text-2xs text-muted">
 Preparing the employee batch…
 {startedAt && <> Started {formatRelative(startedAt)}.</>}
 </p>
 )}
 </div>
 );
}
