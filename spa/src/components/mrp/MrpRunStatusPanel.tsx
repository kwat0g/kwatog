import { LuActivity, LuCircleCheck, LuClock, LuTriangleAlert } from '@/lib/icons';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { formatDateTime } from '@/lib/formatDate';
import type { MrpRun } from '@/types/mrp-runs';

interface Props {
 latest: MrpRun | null | undefined;
 recent?: MrpRun[];
}

const statusVariant: Record<MrpRun['status'], ChipVariant> = {
 running: 'warning',
 completed: 'success',
 failed: 'danger',
};

const triggerVariant: Record<MrpRun['triggered_by'], ChipVariant> = {
 automatic: 'info',
 scheduled: 'info',
 manual: 'neutral',
};

const humanize = (value: string): string => value.replaceAll('_', ' ');

function RunRow({ run }: { run: MrpRun }) {
 const conflicts = run.summary.scheduling?.conflicts.length ?? 0;

 return (
 <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-default py-2 text-xs">
 <span className="font-mono tabular-nums text-muted">{formatDateTime(run.run_at)}</span>
 <Chip variant={triggerVariant[run.triggered_by]}>{run.triggered_by_label ?? humanize(run.triggered_by)}</Chip>
 <Chip variant={statusVariant[run.status]}>{run.status_label ?? humanize(run.status)}</Chip>
 <span className="text-muted">{run.shortages_found} shortages</span>
 {conflicts > 0 && <span className="text-warning-fg">{conflicts} scheduling conflict{conflicts === 1 ? '' : 's'}</span>}
 </div>
 );
}

export function MrpRunStatusPanel({ latest, recent = [] }: Props) {
 if (!latest && recent.length === 0) return null;

 const conflicts = latest?.summary.scheduling?.conflicts.length ?? 0;
 const reason = latest?.summary.trigger_reason;

 return (
 <section className="mx-5 mb-3 rounded-md border border-subtle bg-subtle px-3 py-2.5" aria-label="MRP run status">
 <div className="flex flex-wrap items-start justify-between gap-3">
 <div className="flex min-w-0 items-start gap-2">
 <LuActivity size={14} className="mt-0.5 shrink-0 text-muted" />
 <div className="min-w-0">
 <div className="flex flex-wrap items-center gap-2 text-xs">
 <span className="font-medium text-primary">Latest MRP run</span>
 {latest && <span className="font-mono tabular-nums text-muted">{formatDateTime(latest.run_at)}</span>}
 {latest && <Chip variant={triggerVariant[latest.triggered_by]}>{latest.triggered_by_label ?? humanize(latest.triggered_by)}</Chip>}
 {latest && <Chip variant={statusVariant[latest.status]}>{latest.status_label ?? humanize(latest.status)}</Chip>}
 </div>
 {reason && <p className="mt-1 text-2xs text-muted">Reason: <span className="text-primary">{humanize(reason)}</span></p>}
 {latest?.error_message && (
 <p className="mt-1 flex items-start gap-1 text-2xs text-danger-fg">
 <LuTriangleAlert size={12} className="mt-0.5 shrink-0" />
 {latest.error_message}
 </p>
 )}
 </div>
 </div>
 {latest && (
 <div className="flex flex-wrap items-center gap-3 font-mono tabular-nums text-2xs text-muted">
 <span><span className="text-primary">{latest.sales_orders_evaluated}</span> SOs</span>
 <span><span className="text-primary">{latest.plans_generated}</span> plans</span>
 <span><span className="text-primary">{latest.shortages_found}</span> shortages</span>
 <span><span className="text-primary">{latest.prs_created}</span> PRs</span>
 {conflicts > 0 ? (
 <span className="flex items-center gap-1 text-warning-fg"><LuTriangleAlert size={12} />{conflicts} scheduling conflict{conflicts === 1 ? '' : 's'}</span>
 ) : (
 <span className="flex items-center gap-1 text-success-fg"><LuCircleCheck size={12} />No conflicts</span>
 )}
 {latest.status === 'running' && <span className="flex items-center gap-1 text-warning-fg"><LuClock size={12} />In progress</span>}
 </div>
 )}
 </div>
 {recent.filter((run) => run.id !== latest?.id).length > 0 && (
 <div className="mt-2">
 <div className="text-2xs uppercase tracking-wider text-muted">Recent runs</div>
 {recent.filter((run) => run.id !== latest?.id).slice(0, 5).map((run) => <RunRow key={run.id} run={run} />)}
 </div>
 )}
 </section>
 );
}
