import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, Check, Pencil } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { ActivityStream } from '@/components/chain/ActivityStream';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain';
import { usePermission } from '@/hooks/usePermission';
import type { SalesOrderStatus } from '@/types/crm';

const statusVariant: Record<SalesOrderStatus, 'success' | 'info' | 'warning' | 'neutral' | 'danger'> = {
  draft: 'neutral',
  confirmed: 'info',
  in_production: 'info',
  partially_delivered: 'warning',
  delivered: 'success',
  invoiced: 'success',
  cancelled: 'danger',
};

export default function SalesOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'sales-orders', 'detail', id],
    queryFn: () => salesOrdersApi.show(id!),
    enabled: !!id,
  });
  const chain = useQuery({
    queryKey: ['crm', 'sales-orders', 'chain', id],
    queryFn: () => salesOrdersApi.chain(id!),
    enabled: !!id,
  });

  const confirm = useMutation({
    mutationFn: () => salesOrdersApi.confirm(id!),
    onSuccess: (so) => {
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders'] });
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'detail', id] });
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'chain', id] });
      toast.success(`Sales order ${so.so_number} confirmed.`);
      setConfirmOpen(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to confirm sales order.');
    },
  });

  const cancel = useMutation({
    mutationFn: (reason?: string) => salesOrdersApi.cancel(id!, reason),
    onSuccess: (so) => {
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders'] });
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'detail', id] });
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'chain', id] });
      toast.success(`Sales order ${so.so_number} cancelled.`);
      setCancelOpen(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to cancel sales order.');
    },
  });

  if (isLoading) {
    return (
      <div>
        <PageHeader title="Sales order" backTo="/crm/sales-orders" backLabel="Sales orders" />
        <SkeletonDetail />
      </div>
    );
  }
  if (isError || !data) {
    return (
      <div>
        <PageHeader title="Sales order" backTo="/crm/sales-orders" backLabel="Sales orders" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load sales order"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  const canEdit    = data.is_editable    && can('crm.sales_orders.update');
  const canConfirm = data.is_editable    && can('crm.sales_orders.confirm');
  const canCancel  = data.is_cancellable && can('crm.sales_orders.cancel');

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.so_number}</span>
            <Chip variant={statusVariant[data.status]}>{data.status_label}</Chip>
          </div>
        }
        subtitle={data.customer?.name}
        backTo="/crm/sales-orders"
        backLabel="Sales orders"
        actions={
          <div className="flex gap-1.5">
            {canEdit && (
              <Button size="sm" variant="secondary" icon={<Pencil size={14} />}
                onClick={() => navigate(`/crm/sales-orders/${data.id}/edit`)}>
                Edit
              </Button>
            )}
            {canCancel && (
              <Button size="sm" variant="secondary" icon={<Ban size={14} />}
                onClick={() => setCancelOpen(true)}>
                Cancel
              </Button>
            )}
            {canConfirm && (
              <Button size="sm" variant="primary" icon={<Check size={14} />}
                onClick={() => setConfirmOpen(true)}>
                Confirm order
              </Button>
            )}
          </div>
        }
        bottom={chain.data ? <ChainHeader steps={chain.data} className="mt-2" /> : null}
      />

      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-4">
          <Panel title="Overview">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <dt className="text-muted">SO number</dt>
              <dd className="col-span-2 font-mono">{data.so_number}</dd>
              <dt className="text-muted">Customer</dt>
              <dd className="col-span-2 font-medium">{data.customer?.name ?? '—'}</dd>
              <dt className="text-muted">Date</dt>
              <dd className="col-span-2 font-mono">{data.date}</dd>
              <dt className="text-muted">Payment terms</dt>
              <dd className="col-span-2 font-mono">Net {data.payment_terms_days}</dd>
              <dt className="text-muted">Delivery terms</dt>
              <dd className="col-span-2">{data.delivery_terms ?? '—'}</dd>
              <dt className="text-muted">Created by</dt>
              <dd className="col-span-2">{data.creator?.name ?? '—'}</dd>
              <dt className="text-muted">Notes</dt>
              <dd className="col-span-2 whitespace-pre-line">{data.notes ?? <span className="text-muted">—</span>}</dd>
            </dl>
          </Panel>

          <Panel title="Line items" meta={`${data.item_count} ${data.item_count === 1 ? 'line' : 'lines'}`} noPadding>
            <table className="w-full text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-12">#</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Product</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Qty</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Unit price</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Total</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Delivered</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Delivery</th>
                </tr>
              </thead>
              <tbody>
                {data.items?.map((item, i) => (
                  <tr key={item.id} className="border-t border-subtle hover:bg-subtle">
                    <td className="px-2.5 py-2 font-mono text-muted tabular-nums">{(i + 1).toString().padStart(2, '0')}</td>
                    <td className="px-2.5 py-2">
                      <div className="font-mono">{item.product?.part_number}</div>
                      <div className="text-xs text-muted">{item.product?.name}</div>
                    </td>
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums">{Number(item.quantity).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums">₱ {Number(item.unit_price).toFixed(2)}</td>
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums font-medium">₱ {Number(item.total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums">{Number(item.quantity_delivered).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums">{item.delivery_date}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t border-default bg-subtle">
                  <td className="px-2.5 py-2 text-right text-muted text-2xs uppercase" colSpan={4}>Subtotal</td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums">₱ {Number(data.subtotal).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                  <td colSpan={2} />
                </tr>
                <tr className="border-t border-subtle bg-subtle">
                  <td className="px-2.5 py-2 text-right text-muted text-2xs uppercase" colSpan={4}>VAT (12%)</td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums">₱ {Number(data.vat_amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                  <td colSpan={2} />
                </tr>
                <tr className="border-t border-default bg-subtle">
                  <td className="px-2.5 py-2 text-right text-muted text-2xs uppercase font-medium" colSpan={4}>Total</td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums font-medium text-primary">₱ {Number(data.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                  <td colSpan={2} />
                </tr>
              </tfoot>
            </table>
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="Linked records">
            {/* Sprint 6 audit §3.2: render LinkedRecords with the actual chain
                context — MRP plan + draft/in-progress WOs eager-loaded by
                SalesOrderService::show(). */}
            <LinkedRecords
              groups={[
                ...(data.mrp_plan ? [{
                  label: 'MRP Plan',
                  items: [{
                    id: data.mrp_plan.mrp_plan_no,
                    href: `/mrp/plans/${data.mrp_plan.id}`,
                    meta: `v${data.mrp_plan.version} · ${data.mrp_plan.draft_wo_count} WOs · ${data.mrp_plan.shortages_found} shortages`,
                    chip: { variant: data.mrp_plan.status === 'active' ? 'success' as const : data.mrp_plan.status === 'cancelled' ? 'danger' as const : 'neutral' as const, text: data.mrp_plan.status },
                  }],
                }] : []),
                ...(data.work_orders && data.work_orders.length > 0 ? [{
                  label: 'Work Orders',
                  items: data.work_orders.map((wo) => ({
                    id: wo.wo_number,
                    href: `/production/work-orders/${wo.id}`,
                    meta: `${wo.product?.part_number ?? ''} · ${wo.quantity_produced.toLocaleString()} / ${wo.quantity_target.toLocaleString()}`,
                    chip: { variant: wo.status === 'completed' || wo.status === 'closed' ? 'success' as const : wo.status === 'in_progress' ? 'info' as const : wo.status === 'paused' ? 'warning' as const : wo.status === 'cancelled' ? 'danger' as const : 'neutral' as const, text: wo.status.replace('_', ' ') },
                  })),
                }] : []),
                {
                  label: 'Quality',
                  items: [{ id: 'Inspections', meta: 'Sprint 7 — incoming + in-process + outgoing AQL' }],
                },
                {
                  label: 'Fulfilment',
                  items: [
                    { id: 'Deliveries', meta: 'Sprint 7' },
                    { id: 'Invoice',    meta: 'Sprint 7 (auto on delivery confirm)' },
                  ],
                },
              ]}
            />
          </Panel>
          <Panel title="Activity">
            <ActivityStream
              items={[
                { dot: 'success' as const, text: <>Sales order <span className="font-mono">{data.so_number}</span> created.</>, time: data.created_at?.slice(0, 10) ?? '' },
                ...(data.status !== 'draft' ? [{
                  dot: 'info' as const,
                  text: <>Status: <span className="font-medium">{data.status_label}</span></>,
                  time: data.updated_at?.slice(0, 10) ?? '',
                }] : []),
              ]}
            />
          </Panel>
        </div>
      </div>

      <ConfirmDialog
        isOpen={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => confirm.mutate()}
        title="Confirm sales order?"
        description={
          <>
            Confirming <span className="font-mono font-medium text-primary">{data.so_number}</span> commits the order
            and (once Task 52 lands) triggers the MRP run. Once confirmed it can no longer be edited.
          </>
        }
        confirmLabel="Confirm order"
        variant="primary"
        pending={confirm.isPending}
      />

      <ConfirmDialog
        isOpen={cancelOpen}
        onClose={() => setCancelOpen(false)}
        onConfirm={() => cancel.mutate(undefined)}
        title="Cancel sales order?"
        description={
          <>
            <span className="font-mono font-medium text-primary">{data.so_number}</span> will be marked as cancelled.
            This cannot be undone.
          </>
        }
        confirmLabel="Cancel order"
        variant="danger"
        pending={cancel.isPending}
      />
    </div>
  );
}
