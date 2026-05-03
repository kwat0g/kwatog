/** Sprint 7 — Task 67 — Delivery detail (status progression + receipt photo). */
import { useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { Truck, Camera, Check, ArrowRight } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { deliveriesApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { DeliveryStatus } from '@/types/supplyChain';

const STATUS_CHIP: Record<DeliveryStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  scheduled: 'neutral', loading: 'info', in_transit: 'info',
  delivered: 'warning', confirmed: 'success', cancelled: 'neutral',
};

const NEXT: Record<DeliveryStatus, DeliveryStatus | null> = {
  scheduled: 'loading',
  loading: 'in_transit',
  in_transit: 'delivered',
  delivered: 'confirmed',
  confirmed: null,
  cancelled: null,
};

export default function DeliveryDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const fileInput = useRef<HTMLInputElement | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'deliveries', id],
    queryFn: () => deliveriesApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  const advance = useMutation({
    mutationFn: (next: DeliveryStatus) => deliveriesApi.updateStatus(id, next),
    onSuccess: () => {
      toast.success('Status updated');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const upload = useMutation({
    mutationFn: (file: File) => deliveriesApi.uploadReceipt(id, file),
    onSuccess: () => {
      toast.success('Receipt photo uploaded');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Upload failed'),
  });

  const confirm = useMutation({
    mutationFn: () => deliveriesApi.confirm(id),
    onSuccess: (d) => {
      toast.success(d.invoice ? `Confirmed; draft invoice ${d.invoice.invoice_number} created` : 'Delivery confirmed');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to confirm'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return <EmptyState icon="alert-circle" title="Failed to load delivery"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }

  const next = NEXT[data.status];
  const canConfirm = data.status === 'delivered' && can('supply_chain.deliveries.confirm');

  return (
    <div>
      <PageHeader
        title={
          <span>
            {data.delivery_number}
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">{data.status.replace('_', ' ')}</Chip>
          </span>
        }
        subtitle={data.sales_order ? `SO ${data.sales_order.so_number}` : undefined}
        actions={
          <div className="flex items-center gap-2">
            {next && data.status !== 'delivered' && can('supply_chain.deliveries.create') && (
              <Button variant="secondary" size="sm" icon={<ArrowRight size={14} />}
                loading={advance.isPending} onClick={() => advance.mutate(next)}>
                Mark {next.replace('_', ' ')}
              </Button>
            )}
            {data.status === 'in_transit' && can('supply_chain.deliveries.create') && (
              <Button variant="secondary" size="sm" icon={<Truck size={14} />}
                loading={advance.isPending} onClick={() => advance.mutate('delivered')}>
                Mark delivered
              </Button>
            )}
            {data.status === 'delivered' && can('supply_chain.deliveries.create') && (
              <>
                <input
                  ref={fileInput}
                  type="file"
                  accept="image/*"
                  hidden
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) upload.mutate(file);
                  }}
                />
                <Button variant="secondary" size="sm" icon={<Camera size={14} />}
                  loading={upload.isPending} onClick={() => fileInput.current?.click()}>
                  {data.receipt_photo_url ? 'Replace receipt' : 'Upload receipt'}
                </Button>
              </>
            )}
            {canConfirm && (
              <Button variant="primary" size="sm" icon={<Check size={14} />}
                loading={confirm.isPending} onClick={() => confirm.mutate()}>
                Confirm
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Schedule">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Scheduled</dt>
                <dd className="font-mono tabular-nums">{data.scheduled_date ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Departed</dt>
                <dd className="font-mono tabular-nums">{data.departed_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Delivered</dt>
                <dd className="font-mono tabular-nums">{data.delivered_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Vehicle</dt>
                <dd>{data.vehicle ? `${data.vehicle.name} (${data.vehicle.plate_number})` : '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Driver</dt>
                <dd>{data.driver?.name ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Confirmed</dt>
                <dd className="font-mono tabular-nums">{data.confirmed_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
            </dl>
          </Panel>

          <Panel title="Items" meta={`${data.items?.length ?? 0} ${(data.items?.length ?? 0) === 1 ? 'line' : 'lines'}`} noPadding>
            {!data.items?.length ? (
              <div className="px-4 py-3 text-xs text-muted">No items.</div>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="px-2.5 py-2 text-left text-2xs uppercase tracking-wider text-muted font-medium">Inspection</th>
                    <th className="px-2.5 py-2 text-right text-2xs uppercase tracking-wider text-muted font-medium">Qty</th>
                    <th className="px-2.5 py-2 text-right text-2xs uppercase tracking-wider text-muted font-medium">Unit price</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((i) => (
                    <tr key={i.id} className="border-t border-subtle">
                      <td className="px-2.5 py-2">
                        {i.inspection ? (
                          <Link to={`/quality/inspections/${i.inspection.id}`} className="font-mono text-accent hover:underline">
                            {i.inspection.inspection_number}
                          </Link>
                        ) : <span className="text-muted">—</span>}
                      </td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{i.quantity}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{i.unit_price}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          {data.receipt_photo_url && (
            <Panel title="Receipt photo">
              <a href={data.receipt_photo_url} target="_blank" rel="noopener noreferrer">
                <img src={data.receipt_photo_url} alt="Receipt" className="w-full rounded-md border border-default" />
              </a>
            </Panel>
          )}
          {data.invoice && (
            <Panel title="Invoice">
              <dl className="text-sm space-y-2">
                <div className="flex justify-between">
                  <dt className="text-muted">Number</dt>
                  <dd className="font-mono">{data.invoice.invoice_number}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Total</dt>
                  <dd className="font-mono tabular-nums">{data.invoice.total_amount}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Status</dt>
                  <dd><Chip variant="neutral">{data.invoice.status}</Chip></dd>
                </div>
              </dl>
            </Panel>
          )}
          {data.notes && (
            <Panel title="Notes">
              <p className="whitespace-pre-line text-sm">{data.notes}</p>
            </Panel>
          )}
          <Panel title="Navigation">
            <Link to="/supply-chain/deliveries" className="text-xs text-accent hover:underline">← Back to deliveries</Link>
          </Panel>
        </div>
      </div>
    </div>
  );
}
