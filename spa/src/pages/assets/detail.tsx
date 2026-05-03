/** Sprint 8 — Task 70. Asset detail with depreciation history + dispose modal. */
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { assetsApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';

export default function AssetDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [disposeOpen, setDisposeOpen] = useState(false);
  const [disposalAmount, setDisposalAmount] = useState<string>('0.00');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['asset', id],
    queryFn: () => assetsApi.show(id),
  });

  const dispose = useMutation({
    mutationFn: () => assetsApi.dispose(id, { disposal_amount: disposalAmount }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['asset', id] });
      qc.invalidateQueries({ queryKey: ['assets'] });
      toast.success('Asset disposed and JE posted.');
      setDisposeOpen(false);
    },
    onError: () => toast.error('Failed to dispose asset.'),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) {
    return <EmptyState icon="alert-circle" title="Failed to load asset"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }

  return (
    <div>
      <PageHeader
        title={data.asset_code}
        subtitle={data.name}
        backTo="/assets"
        backLabel="Assets"
        actions={
          <div className="flex gap-1.5 items-center">
            <Chip variant={data.status === 'active' ? 'success' : data.status === 'under_maintenance' ? 'warning' : 'neutral'}>
              {data.status.replace('_', ' ')}
            </Chip>
            {data.status === 'active' && can('assets.dispose') && (
              <Button variant="danger" size="sm" onClick={() => setDisposeOpen(true)}>Dispose</Button>
            )}
          </div>
        }
      />

      <div className="px-5 pt-3 pb-4 grid grid-cols-4 gap-2">
        <StatCard label="Acquisition" value={`₱ ${data.acquisition_cost}`} />
        <StatCard label="Accumulated dep." value={`₱ ${data.accumulated_depreciation}`} />
        <StatCard label="Book value" value={`₱ ${data.book_value}`} />
        <StatCard label="Monthly dep." value={`₱ ${data.monthly_depreciation}`} />
      </div>

      <div className="px-5 pb-6 grid grid-cols-3 gap-4">
        <div className="col-span-2">
          <Panel title="Depreciation history" meta={data.depreciations?.length ? `${data.depreciations.length} months` : undefined}>
            {data.depreciations && data.depreciations.length > 0 ? (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-subtle">
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Period</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Amount</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Accumulated</th>
                  </tr>
                </thead>
                <tbody>
                  {data.depreciations.map((d) => (
                    <tr key={d.id} className="border-b border-subtle h-8">
                      <td className="font-mono tabular-nums">{d.period_year}-{String(d.period_month).padStart(2, '0')}</td>
                      <td className="text-right font-mono tabular-nums">₱{d.depreciation_amount}</td>
                      <td className="text-right font-mono tabular-nums">₱{d.accumulated_after}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="text-sm text-muted">No depreciation posted yet.</p>
            )}
          </Panel>
        </div>
        <aside>
          <Panel title="Details">
            <dl className="text-sm divide-y divide-subtle">
              <Row label="Category">{data.category}</Row>
              <Row label="Acquired">{data.acquisition_date}</Row>
              <Row label="Useful life">{data.useful_life_years} years</Row>
              <Row label="Salvage"><span className="font-mono">₱{data.salvage_value}</span></Row>
              <Row label="Location">{data.location ?? '—'}</Row>
              <Row label="Department">{data.department?.name ?? '—'}</Row>
              {data.disposed_date && <Row label="Disposed">{data.disposed_date}</Row>}
              {data.disposal_amount && <Row label="Proceeds"><span className="font-mono">₱{data.disposal_amount}</span></Row>}
            </dl>
          </Panel>
        </aside>
      </div>

      <Modal isOpen={disposeOpen} onClose={() => setDisposeOpen(false)} size="sm" title="Dispose asset">
        <div className="py-3 space-y-3">
          <p className="text-sm text-secondary">
            Disposing posts a journal entry that nets accumulated depreciation against the asset cost and books gain or loss against the proceeds.
          </p>
          <Input label="Disposal proceeds (₱)" value={disposalAmount}
            onChange={(e) => setDisposalAmount(e.target.value)} className="font-mono" />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button variant="secondary" onClick={() => setDisposeOpen(false)}>Cancel</Button>
          <Button variant="danger" onClick={() => dispose.mutate()} loading={dispose.isPending}>
            {dispose.isPending ? 'Disposing…' : 'Confirm dispose'}
          </Button>
        </div>
      </Modal>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between py-1.5">
      <span className="text-xs uppercase tracking-wider text-muted">{label}</span>
      <span>{children}</span>
    </div>
  );
}
