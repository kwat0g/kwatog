/** Sprint 8 — Task 71. Separation/clearance detail with sign + final-pay + finalize flow. */
import { useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Check } from 'lucide-react';
import { separationsApi } from '@/api/separations';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ChainStep } from '@/types/chain';
import type { ClearanceStatus } from '@/types/separations';

const STATUS_CHIP: Record<ClearanceStatus, 'success' | 'warning' | 'info' | 'neutral'> = {
  pending: 'warning', in_progress: 'info', completed: 'info', finalized: 'success', cancelled: 'neutral',
};

export default function SeparationDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['clearance', id],
    queryFn: () => separationsApi.show(id),
  });

  const sign = useMutation({
    mutationFn: (item_key: string) => separationsApi.signItem(id, item_key),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['clearance', id] }); toast.success('Item cleared.'); },
    onError: () => toast.error('Failed to sign item.'),
  });
  const compute = useMutation({
    mutationFn: () => separationsApi.computeFinalPay(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['clearance', id] }); toast.success('Final pay computed.'); },
    onError: () => toast.error('Failed to compute final pay.'),
  });
  const finalize = useMutation({
    mutationFn: () => separationsApi.finalize(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['clearance', id] });
      qc.invalidateQueries({ queryKey: ['hr', 'separations'] });
      toast.success('Separation finalized; JE posted.');
    },
    onError: () => toast.error('Failed to finalize.'),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) return <EmptyState icon="alert-circle" title="Failed to load" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  const steps: ChainStep[] = [
    { key: 'initiated', label: 'Initiated', state: 'done' },
    { key: 'items_cleared', label: 'Items cleared', state: data.cleared_count === data.items_total ? 'done' : 'active' },
    { key: 'final_pay', label: 'Final pay computed', state: data.final_pay_computed ? 'done' : data.cleared_count === data.items_total ? 'active' : 'pending' },
    { key: 'finalized', label: 'Finalized', state: data.status === 'finalized' ? 'done' : data.final_pay_computed ? 'active' : 'pending' },
  ];

  const allItemsCleared = data.cleared_count === data.items_total;
  const breakdownEntries = data.final_pay_breakdown ? Object.entries(data.final_pay_breakdown) : [];
  const grouped: Record<string, typeof data.clearance_items> = {};
  for (const item of data.clearance_items) (grouped[item.department] ??= []).push(item);

  return (
    <div>
      <PageHeader
        title={data.clearance_no}
        subtitle={data.employee?.full_name ?? undefined}
        backTo="/hr/separations"
        backLabel="Separations"
        actions={
          <div className="flex gap-1.5 items-center">
            <Chip variant={STATUS_CHIP[data.status]}>{data.status.replace('_', ' ')}</Chip>
            {allItemsCleared && !data.final_pay_computed && can('hr.separation.finalize') && (
              <Button variant="primary" size="sm" onClick={() => compute.mutate()} loading={compute.isPending}>
                Compute final pay
              </Button>
            )}
            {data.final_pay_computed && data.status !== 'finalized' && can('hr.separation.finalize') && (
              <Button variant="primary" size="sm" onClick={() => finalize.mutate()} loading={finalize.isPending}>
                Finalize
              </Button>
            )}
          </div>
        }
      />
      <div className="px-5 pt-3 pb-4"><ChainHeader steps={steps} /></div>

      <div className="px-5 pb-4 grid grid-cols-4 gap-2">
        <StatCard label="Progress" value={`${data.progress_pct}%`} />
        <StatCard label="Cleared" value={`${data.cleared_count} / ${data.items_total}`} />
        <StatCard label="Reason" value={data.separation_reason.replace('_', ' ')} />
        <StatCard label="Final pay" value={data.final_pay_amount ? `₱ ${data.final_pay_amount}` : '—'} />
      </div>

      <div className="px-5 pb-6 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          {Object.entries(grouped).map(([dept, items]) => (
            <Panel key={dept} title={dept}>
              <ul className="divide-y divide-subtle">
                {items.map((item) => (
                  <li key={item.item_key} className="py-2 flex items-center justify-between gap-3">
                    <div className="min-w-0">
                      <div className="text-sm font-medium">{item.label}</div>
                      <div className="text-xs text-muted font-mono">
                        {item.status === 'cleared' && item.signed_at
                          ? <><Check size={11} className="inline mr-1" />Cleared {item.signed_at.slice(0, 10)}</>
                          : 'Pending'}
                      </div>
                    </div>
                    {item.status === 'pending' && data.status !== 'finalized' && can('hr.clearance.sign') && (
                      <Button variant="secondary" size="sm" onClick={() => sign.mutate(item.item_key)} loading={sign.isPending}>
                        Sign
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            </Panel>
          ))}
        </div>

        <aside className="space-y-3">
          <Panel title="Final pay breakdown">
            {breakdownEntries.length === 0 ? (
              <p className="text-sm text-muted">Compute final pay to see the breakdown.</p>
            ) : (
              <dl className="text-sm divide-y divide-subtle">
                {breakdownEntries.map(([k, v]) => (
                  <div key={k} className="flex justify-between py-1.5">
                    <span className="text-xs uppercase tracking-wider text-muted">{k.replace(/_/g, ' ')}</span>
                    <span className="font-mono tabular-nums">₱{v}</span>
                  </div>
                ))}
              </dl>
            )}
            {data.journal_entry && (
              <div className="mt-3 pt-3 border-t border-subtle text-xs">
                JE posted: <span className="font-mono">{data.journal_entry.entry_number}</span>
              </div>
            )}
          </Panel>
        </aside>
      </div>
    </div>
  );
}
