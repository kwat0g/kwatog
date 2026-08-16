import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
 LuCircleCheck,
 LuClock3,
 LuExternalLink,
 LuGitBranch,
 LuRefreshCw,
 LuRotateCcw,
 LuSearch,
 LuShieldCheck,
 LuStickyNote,
} from '@/lib/icons';
import toast from 'react-hot-toast';
import { chainApi } from '@/api/chain';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { formatDateTime, formatRelative } from '@/lib/formatDate';
import { cn } from '@/lib/cn';
import { useDebounce } from '@/hooks/useDebounce';
import type {
 ChainListenerOutcomeStatus,
 ChainListenerQueueStatus,
 ChainListenerResolutionStatus,
 ChainListenerRun,
 ChainListenerRunsData,
} from '@/types/chain';

const QUEUE_VARIANT: Record<ChainListenerQueueStatus, ChipVariant> = {
 processing: 'info',
 retrying: 'warning',
 completed: 'success',
 failed: 'danger',
};

const OUTCOME_VARIANT: Record<ChainListenerOutcomeStatus, ChipVariant> = {
 completed: 'success',
 skipped: 'neutral',
 manual_required: 'warning',
 failed: 'danger',
 unclassified: 'neutral',
};

const RESOLUTION_VARIANT: Record<ChainListenerResolutionStatus, ChipVariant> = {
 open: 'warning',
 resolved: 'success',
 not_required: 'neutral',
};

function shortName(value: string): string {
 return value.split('\\').pop() ?? value;
}

function entityHref(entityType: string, hashId: string): string | null {
 switch (entityType) {
 case 'purchase_request':
 return '/purchasing/purchase-requests/' + hashId;
 case 'purchase_order':
 return '/purchasing/purchase-orders/' + hashId;
 case 'work_order':
 return '/production/work-orders/' + hashId;
 case 'grn':
 return '/inventory/grn/' + hashId;
 case 'bill':
 return '/accounting/bills/' + hashId;
 case 'invoice':
 return '/accounting/invoices/' + hashId;
 case 'delivery':
 return '/supply-chain/deliveries/' + hashId;
 case 'stock_movement':
 return '/inventory/stock-levels?view=movements&movement_id=' + hashId;
 case 'return_request':
 return '/return-management/' + hashId;
 case 'customer_complaint':
 return '/crm/complaints/' + hashId;
 default:
 return null;
 }
}

export default function ChainRecoveryPage() {
 const [page, setPage] = useState(1);
 const [attention, setAttention] = useState<'attention' | 'all'>('attention');
 const [search, setSearch] = useState('');
 // One listener-run query per keystroke against a table that also polls
 // every 30s — the two compounded into a request per character typed.
 const debouncedSearch = useDebounce(search, 300);
 const [replayTarget, setReplayTarget] = useState<ChainListenerRun | null>(null);
 const [resolveTarget, setResolveTarget] = useState<ChainListenerRun | null>(null);
 const queryClient = useQueryClient();
 const { can } = usePermission();
 const canManage = can('dashboard.chain_recovery.manage');

 const query = useQuery<ChainListenerRunsData>({
 queryKey: ['chain', 'listener-runs', attention, page, debouncedSearch],
 queryFn: () => chainApi.listenerRuns({
 attention: attention === 'attention',
 page,
 per_page: 25,
 search: debouncedSearch || undefined,
 }),
 placeholderData: (previous) => previous,
 refetchInterval: 30_000,
 });

 const replay = useMutation({
 mutationFn: (run: ChainListenerRun) => chainApi.replayListenerRun(run.id),
 onSuccess: () => {
 toast.success('The selected listener replay was queued.');
 setReplayTarget(null);
 queryClient.invalidateQueries({ queryKey: ['chain', 'listener-runs'] });
 },
 onError: () => toast.error('The listener replay could not be queued. Review the run and try again.'),
 });

 const resolve = useMutation({
 mutationFn: ({ run, note }: { run: ChainListenerRun; note: string }) =>
 chainApi.resolveListenerRun(run.id, note),
 onSuccess: () => {
 toast.success('Resolution note saved.');
 setResolveTarget(null);
 queryClient.invalidateQueries({ queryKey: ['chain', 'listener-runs'] });
 },
 onError: () => toast.error('The resolution note could not be saved.'),
 });

 const data = query.data;

 return (
 <div>
 <PageHeader
 title="Automation recovery"
 subtitle="Inspect cross-module listener failures, manual handoffs, and safe replay lineage."
 refreshingQueryKey={['chain', 'listener-runs']}
 actions={(
 <Button
 variant="secondary"
 size="sm"
 onClick={() => query.refetch()}
 disabled={query.isFetching}
 icon={<LuRefreshCw size={13} className={cn(query.isFetching && 'animate-spin')} />}
 >
 Refresh
 </Button>
 )}
 />

 <div className="px-5 py-4 space-y-4">
 <Panel
 title="Listener runs"
 meta={data ? String(data.meta.total) + ' ' + (attention === 'attention' ? 'requiring attention' : 'recorded') : undefined}
 >
 <div className="flex flex-col gap-3 border-b border-subtle pb-4 lg:flex-row lg:items-center">
 <SegmentedControl
 label="Recovery scope"
 size="sm"
 value={attention}
 onChange={(value) => {
 setAttention(value);
 setPage(1);
 }}
 options={[
 { value: 'attention', label: 'Needs attention', count: attention === 'attention' ? data?.meta.total : undefined },
 { value: 'all', label: 'All runs', count: attention === 'all' ? data?.meta.total : undefined },
 ]}
 />
 <Input
 aria-label="Search listener runs"
 placeholder="Search listener, event, job, or outbox…"
 value={search}
 onChange={(event) => {
 setSearch(event.target.value);
 setPage(1);
 }}
 prefix={<LuSearch size={12} />}
 containerClassName="w-full lg:ml-auto lg:w-80"
 />
 </div>

 <div className="mt-4 rounded-md border border-info/30 bg-info-bg/30 px-3 py-2 text-xs text-secondary">
 <div className="flex items-start gap-2">
 <LuShieldCheck size={14} className="mt-0.5 shrink-0 text-info-fg" />
 <span>
 Replay is scoped to the selected listener. It leaves the published outbox message and sibling listeners untouched.
 A replay creates a new run linked to the source run.
 </span>
 </div>
 </div>

 {query.isLoading && !data && <div className="mt-4"><SkeletonTable columns={1} rows={6} /></div>}

 {query.isError && (
 <div className="mt-4">
 <EmptyState
 icon="alert-circle"
 title="Could not load listener runs"
 description="The recovery ledger is unchanged. Try loading it again."
 action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>}
 />
 </div>
 )}

 {data && data.items.length === 0 && (
 <div className="mt-4">
 <EmptyState
 icon={attention === 'attention' ? 'check-circle' : 'search'}
 title={attention === 'attention' ? 'No listener runs need attention' : 'No listener runs found'}
 description={attention === 'attention'
 ? 'Queued listeners are either healthy or still inside their retry window.'
 : 'Try a different search term or return to the attention view.'}
 />
 </div>
 )}

 {data && data.items.length > 0 && (
 <div className="mt-4 divide-y divide-subtle">
 {data.items.map((run) => (
 <ListenerRunRow
 key={run.id}
 run={run}
 canManage={canManage}
 onReplay={() => setReplayTarget(run)}
 onResolve={() => setResolveTarget(run)}
 />
 ))}
 <DataTablePagination meta={data.meta} onPageChange={setPage} />
 </div>
 )}
 </Panel>
 </div>

 <ConfirmDialog
 isOpen={!!replayTarget}
 onClose={() => setReplayTarget(null)}
 title="Replay this listener?"
 description={replayTarget ? (
 <span>
 This queues <strong>{shortName(replayTarget.listener_class)}</strong> only. It will read the current referenced record state.
 No sibling listener or outbox status will be replayed.
 </span>
 ) : undefined}
 confirmLabel="Queue replay"
 onConfirm={async () => {
 if (replayTarget) await replay.mutateAsync(replayTarget);
 }}
 pending={replay.isPending}
 />

 <ReasonDialog
 isOpen={!!resolveTarget}
 onClose={() => setResolveTarget(null)}
 title="Record manual resolution"
 description={resolveTarget ? (
 <span>
 Close the operational handoff for <strong>{shortName(resolveTarget.listener_class)}</strong>. This records disposition only; it does not alter the historical queue result.
 </span>
 ) : undefined}
 reasonLabel="Resolution note"
 reasonPlaceholder="What was checked or completed, and by whom?"
 minLength={3}
 maxLength={2000}
 confirmLabel="Save resolution"
 variant="primary"
 onConfirm={async (note) => {
 if (resolveTarget) await resolve.mutateAsync({ run: resolveTarget, note });
 }}
 pending={resolve.isPending}
 />
 </div>
 );
}

function ListenerRunRow({
 run,
 canManage,
 onReplay,
 onResolve,
}: {
 run: ChainListenerRun;
 canManage: boolean;
 onReplay: () => void;
 onResolve: () => void;
}) {
 const isActionable = !['processing', 'retrying'].includes(run.queue.status)
 && (run.queue.status === 'failed'
 || run.outcome.status === 'failed'
 || run.outcome.status === 'manual_required');
 const entityLink = run.chain_step?.entity_hash_id
 ? entityHref(run.chain_step.entity_type, run.chain_step.entity_hash_id)
 : null;

 return (
 <article className="py-4 first:pt-0 last:pb-0">
 <div className="flex flex-col gap-3 xl:flex-row xl:items-start">
 <div className="min-w-0 flex-1">
 <div className="flex flex-wrap items-center gap-2">
 <span className="font-medium text-primary">{shortName(run.listener_class)}</span>
 <Chip variant={QUEUE_VARIANT[run.queue.status]}>{run.queue.status}</Chip>
 <Chip variant={OUTCOME_VARIANT[run.outcome.status]}>{run.outcome.status.replace('_', ' ')}</Chip>
 <Chip variant={RESOLUTION_VARIANT[run.resolution.status]}>{run.resolution.status.replace('_', ' ')}</Chip>
 </div>
 <div className="mt-1 font-mono text-xs text-secondary break-all">{shortName(run.event_type)}::{run.listener_method}</div>
 {run.outcome.message && (
 <p className="mt-2 text-sm text-secondary">{run.outcome.message}</p>
 )}
 {run.queue.last_error && (
 <pre className="mt-2 max-h-24 overflow-auto whitespace-pre-wrap break-words rounded bg-danger-bg/40 px-2 py-1.5 text-2xs text-danger-fg">{run.queue.last_error}</pre>
 )}
 <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-muted">
 <span className="inline-flex items-center gap-1"><LuClock3 size={11} /> {formatRelative(run.created_at)}</span>
 <span>{run.queue.attempts} attempt{run.queue.attempts === 1 ? '' : 's'}</span>
 {run.outcome.code && <span className="font-mono">{run.outcome.code}</span>}
 {run.replay.count > 0 && <span className="inline-flex items-center gap-1"><LuRotateCcw size={11} /> {run.replay.count} replay{run.replay.count === 1 ? '' : 's'}</span>}
 </div>
 </div>

 <div className="grid gap-2 text-2xs text-muted xl:w-[32rem] xl:grid-cols-2">
 <Correlation label="Outbox" value={run.correlation.outbox_id} />
 <Correlation label="Job" value={run.correlation.job_uuid} />
 {run.correlation.replayed_from_id && <Correlation label="Replayed from" value={run.correlation.replayed_from_id} />}
 {run.chain_step && (
 <div className="flex items-center gap-1.5 min-w-0">
 <LuGitBranch size={11} className="shrink-0" />
 <span className="truncate">{run.chain_step.chain} · {run.chain_step.step} · {run.chain_step.entity_type}</span>
 {entityLink && (
 <Link to={entityLink} className="shrink-0 text-accent hover:underline" aria-label="Open correlated record">
 <LuExternalLink size={11} />
 </Link>
 )}
 </div>
 )}
 {run.resolution.resolved_by && (
 <div className="flex items-center gap-1.5 min-w-0">
 <LuStickyNote size={11} className="shrink-0" />
 <span className="truncate">Resolved by {run.resolution.resolved_by} · {formatDateTime(run.resolution.resolved_at)}</span>
 </div>
 )}
 </div>
 </div>

 {canManage && (
 <div className="flex shrink-0 flex-wrap gap-2 xl:justify-end">
 <Button
 size="sm"
 variant="secondary"
 icon={<LuRotateCcw size={12} />}
 onClick={onReplay}
 disabled={!isActionable || run.resolution.status === 'resolved'}
 title={!isActionable ? 'Only failed or manual-handoff runs can be replayed.' : undefined}
 >
 Replay
 </Button>
 <Button
 size="sm"
 variant="primary"
 icon={<LuCircleCheck size={12} />}
 onClick={onResolve}
 disabled={!isActionable || run.resolution.status === 'resolved'}
 title={!isActionable ? 'Only failed or manual-handoff runs can be resolved.' : undefined}
 >
 Resolve
 </Button>
 </div>
 )}
 </article>
 );
}

function Correlation({ label, value }: { label: string; value: string }) {
 return (
 <div className="min-w-0">
 <span className="mr-1">{label}:</span>
 <span className="font-mono break-all text-text-subtle">{value}</span>
 </div>
 );
}
