import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
 AlertTriangle,
 BellRing,
 CheckCheck,
 ChevronRight,
 ClipboardCheck,
 Clock4,
 ExternalLink,
 Factory,
 ListChecks,
 RefreshCw,
 Search,
 ShieldCheck,
 Truck,
 UserCheck,
 Wrench,
 type LucideIcon,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { actionCenterApi } from '@/api/actionCenter';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { filterActionItems } from '@/lib/actionCenter';
import { cn } from '@/lib/cn';
import { formatDateTime, formatRelative } from '@/lib/formatDate';
import { focusRingInset } from '@/lib/focus';
import type { ActionCategory, ActionCenterItem, ActionPriority } from '@/types/actionCenter';

const CATEGORY_ICONS: Record<ActionCategory, LucideIcon> = {
 approval: ClipboardCheck,
 alert: BellRing,
 quality: ShieldCheck,
 maintenance: Wrench,
 production: Factory,
 supply_chain: Truck,
};

const PRIORITY_VARIANT: Record<ActionPriority, ChipVariant> = {
 critical: 'danger', high: 'warning', medium: 'info', low: 'neutral',
};

export default function ActionCenterPage() {
 const navigate = useNavigate();
 const [searchParams, setSearchParams] = useSearchParams();
 // Exceptions fold 2026-08-08: the old /exceptions page was this same queue
 // with the `approval` category filtered out (ActionCenterService::exceptions
 // drops `category === 'approval'`). Now it is a scope toggle here, kept in
 // the URL so refresh/back and the old deep link ?scope=exceptions work.
 const scope = searchParams.get('scope') === 'exceptions' ? 'exceptions' : 'all';
 const [category, setCategory] = useState<ActionCategory | 'all'>('all');
 const [search, setSearch] = useState('');
 const qc = useQueryClient();
 const [selected, setSelected] = useState<Set<string>>(new Set());
 const query = useQuery({
 queryKey: ['action-center'],
 queryFn: actionCenterApi.get,
 refetchInterval: 60_000,
 });

 // Triage actions folded in from the Exception Workbench 2026-08-08: the old
 // /exceptions page could claim/acknowledge/snooze/resolve items in bulk. The
 // exceptions scope keeps that capability here so the fold loses no function.
 const triage = useMutation({
 mutationFn: ({ action, snoozed_until }: { action: 'claim' | 'acknowledge' | 'snooze' | 'resolve'; snoozed_until?: string }) =>
 actionCenterApi.updateTasks({ item_ids: [...selected], action, snoozed_until }),
 onSuccess: () => {
 toast.success('Exceptions updated.');
 setSelected(new Set());
 qc.invalidateQueries({ queryKey: ['action-center'] });
 qc.invalidateQueries({ queryKey: ['badges'] });
 },
 onError: () => toast.error('Could not update the selected exceptions.'),
 });

 const toggle = (id: string) => setSelected((current) => {
 const next = new Set(current);
 if (next.has(id)) next.delete(id);
 else next.add(id);
 return next;
 });

 const setScope = (next: 'all' | 'exceptions') => {
 const params = new URLSearchParams(searchParams);
 if (next === 'exceptions') params.set('scope', 'exceptions');
 else params.delete('scope');
 setSearchParams(params, { replace: true });
 };

 const visibleItems = useMemo(() => {
 const items = query.data?.items ?? [];
 const scoped = scope === 'exceptions' ? items.filter((item) => item.category !== 'approval') : items;
 return filterActionItems(scoped, category, search);
 }, [query.data?.items, scope, category, search]);
 const categoryLabels = new Map((query.data?.category_options ?? []).map((option) => [option.value, option.label]));
 const filters = [{ value: 'all' as const, label: 'All work' }, ...(query.data?.category_options ?? [])];
 const scopeCount = scope === 'exceptions'
 ? summaryScopeCount(query.data?.items)
 : undefined;

 const summary = query.data?.summary;

 return (
 <div>
 <PageHeader
 title="Action Center"
 subtitle="One prioritized queue for approvals, quality, and operational exceptions."
 refreshingQueryKey={['action-center']}
 actions={(
 <Button variant="secondary" size="sm" onClick={() => query.refetch()} disabled={query.isFetching}>
 <RefreshCw size={13} className={cn(query.isFetching && 'animate-spin')} />
 Refresh
 </Button>
 )}
 />

 {query.isLoading && <SkeletonTable columns={1} rows={7} />}

 {query.isError && (
 <EmptyState
 icon="alert-circle"
 title="Could not load your action queue"
 description="Your source records are unchanged. Try loading the queue again."
 action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>}
 />
 )}

 {query.data && (
 <div className="p-5 space-y-4">
 <section className="grid grid-cols-2 lg:grid-cols-4 gap-2" aria-label="Action summary">
 <SummaryCard label="Open actions" value={summary?.total ?? 0} icon={ListChecks} />
 <SummaryCard label="Critical" value={summary?.critical ?? 0} icon={AlertTriangle} tone="danger" />
 <SummaryCard label="High priority" value={summary?.high ?? 0} icon={BellRing} tone="warning" />
 <SummaryCard label="Overdue" value={summary?.overdue ?? 0} icon={RefreshCw} tone="danger" />
 </section>

 <section className="rounded-md border border-default bg-canvas">
 <div className="p-3 border-b border-subtle flex flex-col lg:flex-row lg:items-center gap-3">
 <div className="flex-1 flex items-center gap-3 overflow-x-auto">
 <SegmentedControl
 size="sm"
 label="Work scope"
 value={scope}
 onChange={setScope}
 options={[
 { value: 'all', label: 'All work', count: summary?.total },
 { value: 'exceptions', label: 'Exceptions', count: scopeCount },
 ]}
 />
 <SegmentedControl
 size="sm"
 label="Action category"
 value={category}
 onChange={setCategory}
 options={filters.map((filter) => ({
 value: filter.value,
 label: filter.label,
 count: filter.value === 'all' ? summary?.total : summary?.by_category[filter.value],
 }))}
 />
 </div>
 <Input
 aria-label="Search action queue"
 placeholder="Search work, reference, or owner…"
 value={search}
 onChange={(event) => setSearch(event.target.value)}
 prefix={<Search size={12} />}
 containerClassName="w-full lg:w-72"
 />
 </div>

 {scope === 'exceptions' && (
 <div className="rounded-md border border-default bg-canvas p-2 flex items-center gap-2 flex-wrap">
 <span className="text-xs text-muted mr-auto">
 {selected.size} selected · {summary?.overdue ?? 0} overdue · {summary?.unassigned ?? 0} unassigned
 </span>
 <Button size="sm" variant="secondary" disabled={!selected.size} onClick={() => triage.mutate({ action: 'claim' })}>
 <UserCheck size={12} /> Claim
 </Button>
 <Button size="sm" variant="secondary" disabled={!selected.size} onClick={() => triage.mutate({ action: 'acknowledge' })}>
 <CheckCheck size={12} /> Acknowledge
 </Button>
 <Button size="sm" variant="secondary" disabled={!selected.size}
 onClick={() => triage.mutate({ action: 'snooze', snoozed_until: new Date(Date.now() + 4 * 3600_000).toISOString() })}>
 <Clock4 size={12} /> Snooze 4h
 </Button>
 <Button size="sm" variant="primary" disabled={!selected.size} onClick={() => triage.mutate({ action: 'resolve' })}>
 Resolve
 </Button>
 </div>
 )}

 {visibleItems.length === 0 ? (
 <EmptyState
 icon="check-circle"
 title={query.data.items.length === 0 || scopeCount === 0 ? 'You are all caught up' : 'No matching actions'}
 description={query.data.items.length === 0
 ? 'There is no pending work for your current permissions.'
 : 'Change the scope, category, or search term to see more work.'}
 />
 ) : (
 <div className="divide-y divide-subtle">
 {visibleItems.map((item) => {
 const Icon = CATEGORY_ICONS[item.category];
 const isTriage = scope === 'exceptions';
 return (
 <div
 key={item.id}
 className={cn(
 'px-3 py-3 flex items-start gap-3 transition-colors group',
 isTriage ? 'hover:bg-elevated/40' : 'hover:bg-elevated/60 cursor-pointer',
 focusRingInset,
 item.priority === 'critical' && 'border-l-2 border-l-danger',
 item.priority === 'high' && 'border-l-2 border-l-warning',
 )}
 onClick={isTriage ? undefined : () => navigate(item.link)}
 >
 {isTriage && (
 <Checkbox
 aria-label={`Select ${item.title}`}
 className="mt-1"
 checked={selected.has(item.id)}
 onChange={() => toggle(item.id)}
 />
 )}
 <span className="mt-0.5 p-1.5 rounded-md bg-elevated text-muted shrink-0">
 <Icon size={14} />
 </span>
 <span className="min-w-0 flex-1">
 <span className="flex items-center gap-2 flex-wrap">
 <span className="text-sm font-medium text-primary">{item.title}</span>
 <Chip variant={PRIORITY_VARIANT[item.priority]}>{item.priority_label ?? item.priority}</Chip>
 {item.is_overdue && <Chip variant="danger">overdue</Chip>}
 {isTriage
 ? <Chip variant="neutral">{item.task_state_label ?? item.task_state}</Chip>
 : <Chip variant="neutral">{categoryLabels.get(item.category) ?? item.category}</Chip>}
 </span>
 <span className="block text-xs text-muted mt-1 line-clamp-2">{item.description}</span>
 <span className="flex items-center gap-x-3 gap-y-1 flex-wrap mt-1.5 text-2xs text-subtle">
 {item.reference && <span className="font-mono">{item.reference}</span>}
 <span>{item.status_label}</span>
 {item.assigned_to
 ? <span>Assigned: {item.assigned_to.name}</span>
 : item.owner_label && <span>Source owner: {item.owner_label}</span>}
 {!isTriage && <span>{item.task_state_label ?? item.task_state}</span>}
 {item.created_at && (
 <span title={formatDateTime(item.created_at)}>{formatRelative(item.created_at)}</span>
 )}
 {item.due_at && <span title={formatDateTime(item.due_at)}>Due {formatRelative(item.due_at)}</span>}
 </span>
 </span>
 {isTriage ? (
 <Button
 aria-label="Open source record"
 size="sm"
 variant="secondary"
 onClick={() => navigate(item.link)}
 >
 <ExternalLink size={12} />
 </Button>
 ) : (
 <ChevronRight size={14} className="mt-2 shrink-0 text-subtle group-hover:text-primary" />
 )}
 </div>
 );
 })}
 </div>
 )}
 </section>
 </div>
 )}
 </div>
 );
}

/** Count of non-approval items — mirrors ActionCenterService::exceptions(). */
function summaryScopeCount(items?: ActionCenterItem[]): number | undefined {
 if (!items) return undefined;
 return items.filter((item) => item.category !== 'approval').length;
}

function SummaryCard({
 label,
 value,
 icon: Icon,
 tone = 'default',
}: {
 label: string;
 value: number;
 icon: LucideIcon;
 tone?: 'default' | 'danger' | 'warning';
}) {
 return (
 <div className="rounded-md border border-default bg-canvas p-3 flex items-center gap-3">
 <span className={cn(
 'p-2 rounded-md bg-elevated text-muted',
 tone === 'danger' && 'bg-danger-bg text-danger-fg',
 tone === 'warning' && 'bg-warning-bg text-warning-fg',
 )}>
 <Icon size={15} />
 </span>
 <span>
 <span className="block text-xl font-medium font-mono tabular-nums text-primary">{value}</span>
 <span className="block text-2xs text-muted">{label}</span>
 </span>
 </div>
 );
}
