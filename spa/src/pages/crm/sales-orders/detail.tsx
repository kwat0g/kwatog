import { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuBan, LuCheck, LuPencil, LuFileText } from '@/lib/icons';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { invoicesApi } from '@/api/accounting/invoices';
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
import { buildO2cChain } from '@/lib/chains';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso, formatInt, formatDecimal } from '@/lib/formatNumber';
import { useChainProgress } from '@/hooks/useChainProgress';
import { ChainResultModal, ChainErrorPanel } from './chain-result';
import type { SalesOrderStatus, SoChainResult } from '@/types/crm';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

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
 const [chainResult, setChainResult] = useState<SoChainResult | null>(null);
 const [confirmError, setConfirmError] = useState<{ message: string; errors?: Record<string, string[]> } | null>(null);
 const [finalizeInvoiceId, setFinalizeInvoiceId] = useState<string | null>(null);

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

 // Series C — Task C4. Real-time chain progress.
 useChainProgress('sales_order', id, ['crm', 'sales-orders', 'detail', id]);

 const confirm = useMutation({
 mutationFn: () => salesOrdersApi.confirm(id!),
 onSuccess: (result) => {
 qc.invalidateQueries({ queryKey: ['crm', 'sales-orders'] });
 qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'detail', id] });
 qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'chain', id] });
 setConfirmOpen(false);
 setConfirmError(null);
 setChainResult(result.chain_result);
 toast.success(`Sales order ${result.data.so_number} confirmed.`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 const msg = e.response?.data?.message ?? 'Failed to confirm sales order.';
 const errors = e.response?.data?.errors;
 setConfirmError({ message: msg, errors });
 toast.error(msg);
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

 const finalizeInvoice = useMutation({
 mutationFn: (invoiceId: string) => invoicesApi.finalize(invoiceId),
 onSuccess: () => {
 toast.success('Draft invoice finalized to AR + GL.');
 setFinalizeInvoiceId(null);
 qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'detail', id] });
 qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
 },
 onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to finalize invoice.'),
 });

 if (isLoading) {
 return (
 <div>
 <PageHeader title="Sales order" backTo="/crm/sales-orders" backLabel="Sales orders"
 />
 <SkeletonDetail />
 </div>
 );
 }
 if (isError || !data) {
 return (
 <div>
 <PageHeader title="Sales order" backTo="/crm/sales-orders" backLabel="Sales orders"
 />
 <EmptyState
 icon="alert-circle"
 title="Failed to load sales order"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 </div>
 );
 }

 const canEdit = data.is_editable && can('crm.sales_orders.update');
 const canConfirm = data.is_editable && can('crm.sales_orders.confirm');
 const canCancel = data.is_cancellable && can('crm.sales_orders.cancel');

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
 <Button size="sm" variant="secondary" icon={<LuPencil size={14} />}
 onClick={() => navigate(`/crm/sales-orders/${data.id}/edit`)}>
 Edit
 </Button>
 )}
 {canCancel && (
 <Button size="sm" variant="secondary" icon={<LuBan size={14} />}
 onClick={() => setCancelOpen(true)}>
 Cancel
 </Button>
 )}
 {canConfirm && (
 <Button size="sm" variant="primary" icon={<LuCheck size={14} />}
 onClick={() => setConfirmOpen(true)}>
 Confirm order
 </Button>
 )}
 </div>
 }
 bottom={chain.data ? <ChainHeader steps={chain.data} className="mt-2" /> : null}
 />

 <div className="px-5 py-4 space-y-4">
 <ChainErrorPanel error={confirmError} onDismiss={() => setConfirmError(null)} />

 {/* 2026-08-08 — compact cross-document stepper: the whole chain at a glance.
 The MRP chain above stays; this shows the downstream O2C completion. */}
 <Panel title="Order-to-cash chain">
 <ChainHeader steps={buildO2cChain({
  so: { id: data.id, number: data.so_number },
  delivery: data.deliveries?.[0]
  ? { id: data.deliveries[0].id, number: data.deliveries[0].delivery_number }
  : null,
  deliveryStatus: data.deliveries?.some((d) => d.status === 'delivered' || d.status === 'confirmed')
  ? 'confirmed'
  : (data.deliveries?.[0]?.status ?? null),
  invoices: (data.invoices ?? []).map((inv) => ({
  id: inv.id,
  invoice_number: inv.invoice_number,
  status: inv.status,
  })),
 })} />
 </Panel>

 {/* 2026-08-08 — auto-invoice chain: confirmed deliveries stage draft AR
  invoices; review and finalize them here (mirrors the P2P auto-bill banner). */}
 {data.invoices?.some((inv) => inv.status === 'draft') && (
 <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
 <LuFileText size={16} className="shrink-0 text-success-fg" />
 <div className="flex-1">
 <div className="font-medium">Customer invoice auto-created</div>
 <div className="text-muted">
 Draft AR invoices were staged from the confirmed deliveries —{' '}
 {data.invoices.filter((inv) => inv.status === 'draft').map((inv) => (
 <span key={inv.id} className="inline-flex items-center gap-1.5">
 <Link to={`/accounting/invoices/${inv.id}`} className="font-mono text-accent hover:underline">{inv.invoice_number ?? '(draft)'}</Link>
 <span className="font-mono tabular-nums">{formatPeso(inv.total_amount)}</span>
 {can('accounting.invoices.create') && (
 <Button variant="secondary" size="xs" icon={<LuCheck size={11} />} onClick={() => setFinalizeInvoiceId(inv.id)}>
 Finalize
 </Button>
 )}
 </span>
 ))}
 </div>
 </div>
 </div>
 )}

 <div className="grid gap-4 lg:grid-cols-3">
 <div className="lg:col-span-2 space-y-4">
 <Panel title="Overview">
 <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 text-sm">
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
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th className="w-12">#</Th>
 <Th>Product</Th>
 <Th align="right">Qty</Th>
 <Th align="right">Unit price</Th>
 <Th align="right">Total</Th>
 <Th align="right">Delivered</Th>
 <Th align="right">Delivery</Th>
 </tr>
 </thead>
 <tbody>
 {data.items?.map((item, i) => (
 <tr key={item.id} className={trCls}>
 <Td mono className="text-muted">{(i + 1).toString().padStart(2, '0')}</Td>
 <Td>
 <div className="font-mono">{item.product?.part_number}</div>
 <div className="text-xs text-muted">{item.product?.name}</div>
 </Td>
 <Td align="right" mono>{formatDecimal(item.quantity)}</Td>
 <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
 <Td align="right" mono className="font-medium">{formatPeso(item.total)}</Td>
 <Td align="right" mono>{formatDecimal(item.quantity_delivered)}</Td>
 <Td align="right" mono>{item.delivery_date}</Td>
 </tr>
 ))}
 </tbody>
 <tfoot>
 <tr className={cn(trCls, 'bg-subtle')}>
 <Td align="right" mono className="text-muted text-2xs uppercase" colSpan={4}>Subtotal</Td>
 <Td align="right" mono>{formatPeso(data.subtotal)}</Td>
 <Td colSpan={2} />
 </tr>
 <tr className={cn(trCls, 'bg-subtle')}>
 <Td align="right" mono className="text-muted text-2xs uppercase" colSpan={4}>VAT</Td>
 <Td align="right" mono>{formatPeso(data.vat_amount)}</Td>
 <Td colSpan={2} />
 </tr>
 <tr className={cn(trCls, 'bg-subtle')}>
 <Td align="right" mono className="text-muted text-2xs uppercase font-medium" colSpan={4}>Total</Td>
 <Td align="right" mono className="font-medium text-primary">{formatPeso(data.total_amount)}</Td>
 <Td colSpan={2} />
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
 chip: { variant: data.mrp_plan.status === 'active' ? 'success' as const : data.mrp_plan.status === 'cancelled' ? 'danger' as const : 'neutral' as const, text: data.mrp_plan.status_label ?? data.mrp_plan.status },
 }],
 }] : []),
 ...(data.work_orders && data.work_orders.length > 0 ? [{
 label: 'Work Orders',
 items: data.work_orders.map((wo) => ({
 id: wo.wo_number,
 href: `/production/work-orders/${wo.id}`,
 meta: `${wo.product?.part_number ?? ''} · ${formatInt(wo.quantity_produced)} / ${formatInt(wo.quantity_target)}`,
 chip: { variant: wo.status === 'completed' || wo.status === 'closed' ? 'success' as const : wo.status === 'in_progress' ? 'info' as const : wo.status === 'paused' ? 'warning' as const : wo.status === 'cancelled' ? 'danger' as const : 'neutral' as const, text: wo.status_label ?? wo.status.replace('_', ' ') },
 })),
 }] : []),
 ...(data.inspections && data.inspections.length > 0 ? [{
 label: 'Quality inspections',
 items: data.inspections.map((inspection) => ({
 id: inspection.inspection_number,
 href: `/quality/inspections/${inspection.id}`,
 meta: inspection.stage_label ?? inspection.stage.replace('_', ' '),
 chip: { variant: inspection.status === 'passed' ? 'success' as const : inspection.status === 'failed' ? 'danger' as const : inspection.status === 'in_progress' ? 'info' as const : 'neutral' as const, text: inspection.status_label ?? inspection.status.replace('_', ' ') },
 })),
 }] : []),
 ...(data.deliveries && data.deliveries.length > 0 ? [{
 label: 'Deliveries',
 items: data.deliveries.map((delivery) => ({
 id: delivery.delivery_number,
 href: `/supply-chain/deliveries/${delivery.id}`,
 meta: delivery.scheduled_date ?? undefined,
 chip: { variant: delivery.status === 'confirmed' || delivery.status === 'delivered' ? 'success' as const : delivery.status === 'in_transit' ? 'info' as const : delivery.status === 'cancelled' ? 'danger' as const : 'neutral' as const, text: delivery.status_label ?? delivery.status.replace('_', ' ') },
 })),
 }] : []),
 ...(data.invoices && data.invoices.length > 0 ? [{
 label: 'Invoices',
 items: data.invoices.map((invoice) => ({
 id: invoice.invoice_number,
 href: `/accounting/invoices/${invoice.id}`,
 meta: `${formatPeso(invoice.total_amount)} · balance ${formatPeso(invoice.balance)}`,
 chip: { variant: invoice.status === 'paid' ? 'success' as const : invoice.status === 'overdue' ? 'danger' as const : invoice.status === 'partial' ? 'warning' as const : 'neutral' as const, text: invoice.status_label ?? invoice.status.replace('_', ' ') },
 })),
 }] : []),
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
 </div>

 <ConfirmDialog
 isOpen={confirmOpen}
 onClose={() => setConfirmOpen(false)}
 onConfirm={() => confirm.mutate()}
 title={`Confirm ${data.so_number}?`}
 description={
 <div className="space-y-2 text-sm">
 <p>
 Customer: <span className="font-medium text-primary">{data.customer?.name}</span>
 {' · '}{data.item_count} line items{' · '}
 <span className="font-mono">{formatPeso(data.total_amount)}</span>
 </p>
 <p className="text-muted">Confirming this order will automatically:</p>
 <ul className="list-none space-y-1 text-muted">
 <li>✓ Run MRP and check material availability</li>
 <li>✓ Create Work Orders for all {data.item_count} lines</li>
 <li>✓ Schedule production on available machines</li>
 <li>✓ Reserve required materials in inventory</li>
 <li>✓ Notify Production, Warehouse, and PPC teams</li>
 </ul>
 </div>
 }
 confirmLabel="Confirm & Start Chain"
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

 {/* 2026-08-08 — finalize the auto-created draft invoice from the SO. */}
 <ConfirmDialog
 isOpen={!!finalizeInvoiceId}
 onClose={() => setFinalizeInvoiceId(null)}
 onConfirm={() => { if (finalizeInvoiceId) finalizeInvoice.mutate(finalizeInvoiceId); }}
 title="Finalize draft invoice?"
 description="Finalizing locks the invoice number, posts the AR/revenue journal entry, and flips the invoice to Finalized. Review the auto-created amounts before posting."
 confirmLabel="Finalize invoice"
 pending={finalizeInvoice.isPending}
 />

 <ChainResultModal chainResult={chainResult} onClose={() => setChainResult(null)} />
 </div>
 );
}
