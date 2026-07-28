import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { CheckCheck, Clock4, ExternalLink, UserCheck } from 'lucide-react';
import toast from 'react-hot-toast';
import { actionCenterApi } from '@/api/actionCenter';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatRelative } from '@/lib/formatDate';

export default function ExceptionWorkbenchPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const query = useQuery({ queryKey: ['action-center', 'exceptions'], queryFn: actionCenterApi.exceptions, refetchInterval: 60_000 });
  const items = useMemo(() => query.data?.items ?? [], [query.data?.items]);

  const update = useMutation({
    mutationFn: ({ action, snoozed_until }: { action: 'claim' | 'acknowledge' | 'snooze' | 'resolve'; snoozed_until?: string }) =>
      actionCenterApi.updateTasks({ item_ids: [...selected], action, snoozed_until }),
    onSuccess: () => {
      toast.success('Exceptions updated.'); setSelected(new Set());
      qc.invalidateQueries({ queryKey: ['action-center'] }); qc.invalidateQueries({ queryKey: ['badges'] });
    },
    onError: () => toast.error('Could not update the selected exceptions.'),
  });

  const toggle = (id: string) => setSelected((current) => {
    const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); return next;
  });

  return (
    <div>
      <PageHeader title="Exception Workbench" subtitle="Triage and assign operational exceptions without changing their source records." />
      {query.isLoading && <SkeletonTable columns={5} rows={8} />}
      {query.isError && <EmptyState icon="alert-circle" title="Could not load exceptions" action={<Button onClick={() => query.refetch()}>Retry</Button>} />}
      {query.data && <div className="p-5 space-y-3">
        <div className="rounded-md border border-default bg-canvas p-2 flex items-center gap-2 flex-wrap">
          <span className="text-xs text-muted mr-auto">{selected.size} selected · {query.data.summary.overdue} overdue · {query.data.summary.unassigned} unassigned</span>
          <Button size="sm" variant="secondary" disabled={!selected.size} onClick={() => update.mutate({ action: 'claim' })}><UserCheck size={12} /> Claim</Button>
          <Button size="sm" variant="secondary" disabled={!selected.size} onClick={() => update.mutate({ action: 'acknowledge' })}><CheckCheck size={12} /> Acknowledge</Button>
          <Button size="sm" variant="secondary" disabled={!selected.size} onClick={() => update.mutate({ action: 'snooze', snoozed_until: new Date(Date.now() + 4 * 3600_000).toISOString() })}><Clock4 size={12} /> Snooze 4h</Button>
          <Button size="sm" variant="primary" disabled={!selected.size} onClick={() => update.mutate({ action: 'resolve' })}>Resolve</Button>
        </div>
        {items.length === 0 ? <EmptyState icon="check-circle" title="No active exceptions" /> : <div className="rounded-md border border-default bg-canvas divide-y divide-subtle">
          {items.map((item) => <div key={item.id} className="p-3 flex items-start gap-3">
            <input aria-label={`Select ${item.title}`} type="checkbox" className="mt-1" checked={selected.has(item.id)} onChange={() => toggle(item.id)} />
            <div className="min-w-0 flex-1"><div className="flex gap-2 items-center flex-wrap"><span className="text-sm font-medium">{item.title}</span><Chip variant={item.priority === 'critical' ? 'danger' : item.priority === 'high' ? 'warning' : 'info'}>{item.priority}</Chip>{item.is_overdue && <Chip variant="danger">overdue</Chip>}<Chip>{item.task_state}</Chip></div><p className="text-xs text-muted mt-1">{item.description}</p><p className="text-2xs text-text-subtle mt-1">{item.assigned_to ? `Assigned to ${item.assigned_to.name}` : 'Unassigned'}{item.due_at ? ` · Due ${formatRelative(item.due_at)}` : ''}</p></div>
            <Button aria-label="Open source record" size="sm" variant="secondary" onClick={() => navigate(item.link)}><ExternalLink size={12} /></Button>
          </div>)}
        </div>}
      </div>}
    </div>
  );
}
