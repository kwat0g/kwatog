import { DeliveryNegotiationHistory } from '@/components/crm/DeliveryNegotiationHistory';
import { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuBan, LuCheck, LuPencil, LuFileText, LuArrowRight } from '@/lib/icons';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { invoicesApi } from '@/api/accounting/invoices';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { ActivityStream } from '@/components/chain/ActivityStream';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { FulfillmentDialog } from './FulfillmentDialog';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso, formatInt, formatDecimal } from '@/lib/formatNumber';
import { useChainProgress } from '@/hooks/useChainProgress';
import { ChainResultModal, ChainErrorPanel } from './chain-result';
import type { SalesOrderStatus, SoChainResult } from '@/types/crm';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/lib/formatDate';

const statusVariant: Record<
  SalesOrderStatus,
  'success' | 'info' | 'warning' | 'neutral' | 'danger'
> = {
  draft: 'neutral',
  awaiting_confirmation: 'info',
  delivery_negotiation: 'warning',
  confirmed: 'info',
  in_production: 'info',
  partially_delivered: 'warning',
  delivered: 'success',
  invoiced: 'success',
  paid: 'success',
  closed: 'success',
  cancelled: 'danger',
};

export default function SalesOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [fulfillmentOpen, setFulfillmentOpen] = useState(false);
  const [deliveryProposalOpen, setDeliveryProposalOpen] = useState(false);
  const [deliveryProposalDate, setDeliveryProposalDate] = useState('');
  const [deliveryProposalReason, setDeliveryProposalReason] = useState('');
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [chainResult, setChainResult] = useState<SoChainResult | null>(null);
  const [confirmError, setConfirmError] = useState<{
    message: string;
    errors?: Record<string, string[]>;
    code?: string;
  } | null>(null);
  const [finalizeInvoiceId, setFinalizeInvoiceId] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'sales-orders', 'detail', id],
    queryFn: () => salesOrdersApi.show(id!),
    enabled: !!id,
    refetchInterval: (query) =>
      query.state.data && !['closed', 'cancelled'].includes(query.state.data.status)
        ? 10_000
        : false,
  });
  const chain = useQuery({
    queryKey: ['crm', 'sales-orders', 'chain', id],
    queryFn: () => salesOrdersApi.chain(id!),
    enabled: !!id,
    refetchInterval: data && !['closed', 'cancelled'].includes(data.status) ? 10_000 : false,
  });

  // Series C — Task C4. Real-time chain progress.
  useChainProgress(
    'sales_order',
    id,
    ['crm', 'sales-orders', 'detail', id],
    [['crm', 'sales-orders', 'chain', id]],
  );

  const deliveryProposal = useMutation({
    mutationFn: () =>
      salesOrdersApi.proposeDeliveryDate(id!, {
        proposed_delivery_date: deliveryProposalDate,
        notes: deliveryProposalReason.trim(),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders'] });
      setDeliveryProposalOpen(false);
      setDeliveryProposalDate('');
      setDeliveryProposalReason('');
      toast.success('Delivery date proposed. Waiting for customer acceptance.');
    },
    onError: (error: AxiosError<{ message?: string }>) =>
      toast.error(error.response?.data?.message ?? 'Could not propose delivery date.'),
  });

  const confirm = useMutation({
    mutationFn: () => salesOrdersApi.confirm(id!),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders'] });
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'detail', id] });
      qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'chain', id] });
      setConfirmOpen(false);
      setConfirmError(null);
      setChainResult(result.chain_result);
      if (result.chain_result.planning_status === 'failed') {
        toast.error(`Sales order confirmed; MRP planning needs attention.`);
      } else {
        toast.success(
          `Sales order ${result.data.so_number} confirmed. ${result.chain_result.planning_status === 'queued' ? 'MRP planning is pending.' : 'MRP planning completed.'}`,
        );
      }
    },
    onError: (
      e: AxiosError<{ message?: string; errors?: Record<string, string[]>; code?: string }>,
    ) => {
      const msg = e.response?.data?.message ?? 'Failed to confirm sales order.';
      const errors = e.response?.data?.errors;
      setConfirmError({ message: msg, errors, code: e.response?.data?.code });
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
      setCancelReason('');
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
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to finalize invoice.'),
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
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );
  }

  const productionDone = chain.data?.some(
    (step) => step.key === 'in_production' && step.state === 'done',
  );
  const qualityDone = chain.data?.some(
    (step) => step.key === 'qc_outgoing' && step.state === 'done',
  );
  const qualityHeld = chain.data?.some(
    (step) => step.key === 'qc_outgoing' && step.state === 'rejected',
  );
  const progressLabel =
    ['confirmed', 'in_production'].includes(data.status) && productionDone
      ? qualityDone
        ? 'Ready for delivery'
        : qualityHeld
          ? 'Quality hold'
          : 'Awaiting QC'
      : data.status_label;

  const canEdit = data.is_editable && can('crm.sales_orders.update');
  const canConfirm =
    ['draft', 'awaiting_confirmation', 'delivery_negotiation'].includes(data.status) &&
    !data.capabilities?.awaiting_delivery_response &&
    can('crm.sales_orders.confirm');
  const canCancel = data.is_cancellable && can('crm.sales_orders.cancel');
  const activityItems = (data.status_history ?? []).map((event) => ({
    dot:
      event.status === 'cancelled'
        ? ('danger' as const)
        : event.event_type === 'created'
          ? ('success' as const)
          : ('info' as const),
    text: (
      <div className="space-y-0.5">
        <div>
          {event.event_type === 'created' ? (
            <>
              Sales order <span className="font-mono">{data.so_number}</span> created with status{' '}
              <span className="font-medium">{event.status_label}</span>.
            </>
          ) : event.previous_status_label ? (
            <>
              Status changed from <span className="font-medium">{event.previous_status_label}</span>{' '}
              to <span className="font-medium">{event.status_label}</span>.
            </>
          ) : (
            <>
              Status changed to <span className="font-medium">{event.status_label}</span>.
            </>
          )}
        </div>
        <div className="text-2xs text-muted">By {event.actor_name ?? 'Actor not recorded'}</div>
        {event.comment && <div className="text-2xs text-muted">Reason: {event.comment}</div>}
      </div>
    ),
    time: formatDateTime(event.occurred_at, 'Date not recorded'),
  }));

  return (
    <div>
      {fulfillmentOpen && <FulfillmentDialog orderId={data.id} onClose={() => setFulfillmentOpen(false)} />}
      <PageHeader
        title={
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <span className="font-mono">{data.so_number}</span>
            <Chip
              variant={progressLabel === 'Quality hold' ? 'danger' : statusVariant[data.status]}
            >
              {progressLabel}
            </Chip>
          </div>
        }
        subtitle={data.customer?.name}
        backTo="/crm/sales-orders"
        backLabel="Sales orders"
        actions={
          <div className="flex gap-1.5">
            {data.status === 'confirmed' && (can('production.wo.confirm') || can('production.schedule.confirm')) && <Button size="sm" variant="secondary" onClick={() => setFulfillmentOpen(true)}>Review fulfillment</Button>}
            {canEdit && (
              <Button
                size="sm"
                variant="secondary"
                icon={<LuPencil size={14} />}
                onClick={() => navigate(`/crm/sales-orders/${data.id}/edit`)}
              >
                Edit
              </Button>
            )}
            {canCancel && (
              <Button
                size="sm"
                variant="secondary"
                icon={<LuBan size={14} />}
                onClick={() => setCancelOpen(true)}
              >
                Cancel
              </Button>
            )}
            {canConfirm && (
              <Button
                size="sm"
                variant="primary"
                icon={<LuCheck size={14} />}
                onClick={() => setConfirmOpen(true)}
              >
                Confirm order
              </Button>
            )}
          </div>
        }
        bottom={
          <div className="mt-2 space-y-2">
            {chain.data && (
              <ChainHeader
                steps={chain.data.map((step) => ({
                  ...step,
                  href:
                    step.href &&
                    can(
                      step.key === 'mrp_planned'
                        ? 'mrp.plans.view'
                        : step.key === 'in_production'
                          ? 'production.work_orders.view'
                          : 'quality.inspections.view',
                    )
                      ? step.href
                      : undefined,
                }))}
              />
            )}
            {chain.isError && (
              <p role="alert" className="text-sm text-danger-fg">
                Process status could not load. Refresh to check what happened after confirmation.
              </p>
            )}
            <Button
              size="sm"
              variant="secondary"
              disabled={chain.isFetching || isLoading}
              onClick={() => {
                void chain.refetch();
                void refetch();
              }}
            >
              {chain.isFetching ? 'Checking process…' : 'Refresh process'}
            </Button>
          </div>
        }
      />

      <div className="px-5 py-4 space-y-4">
        <ChainErrorPanel error={confirmError} onDismiss={() => setConfirmError(null)} />

        {/* 2026-08-08 — auto-invoice chain: confirmed deliveries stage draft AR
  invoices; review and finalize them here (mirrors the P2P auto-bill banner). */}
        {data.invoices?.some((inv) => inv.status === 'draft') && (
          <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
            <LuFileText size={16} className="shrink-0 text-success-fg" />
            <div className="flex-1">
              <div className="font-medium">Customer invoice auto-created</div>
              <div className="text-muted">
                Draft AR invoices were staged from the confirmed deliveries —{' '}
                {data.invoices
                  .filter((inv) => inv.status === 'draft')
                  .map((inv) => (
                    <span key={inv.id} className="inline-flex items-center gap-1.5">
                      <Link
                        to={`/accounting/invoices/${inv.id}`}
                        className="font-mono text-accent hover:underline"
                      >
                        {inv.invoice_number ?? '(draft)'}
                      </Link>
                      <span className="font-mono tabular-nums">{formatPeso(inv.total_amount)}</span>
                      {can('accounting.invoices.create') && (
                        <Button
                          variant="secondary"
                          size="xs"
                          icon={<LuCheck size={11} />}
                          onClick={() => setFinalizeInvoiceId(inv.id)}
                        >
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
          <div className="min-w-0 lg:col-span-2 space-y-4">
            {data.submission_source === 'customer_portal' && (
              <Panel
                title="Delivery schedule"
                actions={
                  data.capabilities?.can_propose_delivery ? (
                    <Button onClick={() => setDeliveryProposalOpen(!deliveryProposalOpen)}>
                      Propose delivery date
                    </Button>
                  ) : undefined
                }
              >
                <div className="grid gap-3 sm:grid-cols-2 text-sm">
                  <div>
                    <p className="text-muted">Required delivery date</p>
                    <p className="mt-1 font-medium">
                      {(
                        data.requested_delivery_dates ?? [
                          ...new Set(data.items?.map((item) => item.delivery_date)),
                        ]
                      )
                        .map((date) => formatDate(date))
                        .join(', ') || '—'}
                    </p>
                  </div>
                  <div>
                    <p className="text-muted">Current delivery schedule</p>
                    <p className="mt-1 font-medium">
                      {(
                        data.delivery_dates ?? [
                          ...new Set(data.items?.map((item) => item.delivery_date)),
                        ]
                      )
                        .map((date) => formatDate(date))
                        .join(', ') || '—'}
                    </p>
                  </div>
                </div>
                {data.latest_response?.type === 'delivery_date' && (
                  <div className="mt-4 border-t border-default pt-3 text-sm">
                    <p>
                      Proposed date:{' '}
                      <strong>
                        {data.latest_response.proposed_delivery_date
                          ? formatDate(data.latest_response.proposed_delivery_date)
                          : '—'}
                      </strong>{' '}
                      · {data.latest_response.status}
                    </p>
                    <p className="mt-1 whitespace-pre-wrap text-muted">
                      {data.latest_response.notes}
                    </p>
                    {data.latest_response.status === 'rejected' && (
                      <p className="mt-2 text-muted">
                        Customer declined. Propose another date, or confirm only if you can meet the
                        current schedule.
                      </p>
                    )}
                    {data.capabilities?.awaiting_delivery_response && (
                      <p role="status" className="mt-2 text-warning-fg">
                        Waiting for customer acceptance. Confirmation is blocked.
                      </p>
                    )}
                  </div>
                )}
                <DeliveryNegotiationHistory proposals={data.delivery_negotiations} />
                {deliveryProposalOpen && (
                  <form
                    className="mt-4 space-y-3 border-t border-default pt-4"
                    onSubmit={(event) => {
                      event.preventDefault();
                      deliveryProposal.mutate();
                    }}
                  >
                    <Input
                      label="Proposed delivery date"
                      type="date"
                      required
                      value={deliveryProposalDate}
                      min={data.date}
                      onChange={(event) => setDeliveryProposalDate(event.target.value)}
                    />
                    <Textarea
                      label="Reason for changing delivery date"
                      required
                      rows={2}
                      maxLength={1000}
                      value={deliveryProposalReason}
                      onChange={(event) => setDeliveryProposalReason(event.target.value)}
                    />
                    <p className="text-xs text-muted">
                      Applies to the whole order after customer acceptance. Products, quantities and
                      prices stay unchanged.
                    </p>
                    <div className="flex gap-2">
                      <Button
                        type="submit"
                        variant="primary"
                        loading={deliveryProposal.isPending}
                        disabled={
                          deliveryProposal.isPending ||
                          !deliveryProposalDate ||
                          !deliveryProposalReason.trim()
                        }
                      >
                        Send date proposal
                      </Button>
                      <Button
                        disabled={deliveryProposal.isPending}
                        onClick={() => setDeliveryProposalOpen(false)}
                      >
                        Keep current schedule
                      </Button>
                    </div>
                  </form>
                )}
              </Panel>
            )}
            <Panel title="Overview">
              <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 text-sm">
                <dt className="text-muted">SO number</dt>
                <dd className="col-span-2 font-mono">{data.so_number}</dd>
                <dt className="text-muted">Customer</dt>
                <dd className="col-span-2 font-medium">{data.customer?.name ?? '—'}</dd>
                <dt className="text-muted">Date</dt>
                <dd className="col-span-2 font-mono">{data.date}</dd>
                {data.submission_source !== 'customer_portal' && (
                  <>
                    <dt className="text-muted">Required delivery date</dt>
                    <dd className="col-span-2">
                      {(
                        data.requested_delivery_dates ?? [
                          ...new Set(data.items?.map((item) => item.delivery_date)),
                        ]
                      )
                        .map((date) => formatDate(date))
                        .join(', ') || '—'}
                    </dd>
                  </>
                )}
                <dt className="text-muted">Payment terms</dt>
                <dd className="col-span-2 font-mono">Net {data.payment_terms_days}</dd>
                <dt className="text-muted">Delivery terms</dt>
                <dd className="col-span-2">{data.delivery_terms ?? '—'}</dd>
                <dt className="text-muted">Incoterm</dt>
                <dd className="col-span-2">{data.incoterm ?? '—'}</dd>
                <dt className="text-muted">Created by</dt>
                <dd className="col-span-2">{data.creator?.name ?? '—'}</dd>
                <dt className="text-muted">Notes</dt>
                <dd className="col-span-2 whitespace-pre-line">
                  {data.notes ?? <span className="text-muted">—</span>}
                </dd>
              </dl>
            </Panel>

            <Panel
              title="Line items"
              meta={`${data.item_count} ${data.item_count === 1 ? 'line' : 'lines'}`}
              noPadding
            >
              <div className="overflow-x-auto">
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
                        <Td mono className="text-muted">
                          {(i + 1).toString().padStart(2, '0')}
                        </Td>
                        <Td>
                          <div className="font-mono">{item.product?.part_number}</div>
                          <div className="text-xs text-muted">{item.product?.name}</div>
                        </Td>
                        <Td align="right" mono>
                          {formatDecimal(item.quantity)}
                        </Td>
                        <Td align="right" mono>
                          {formatPeso(item.unit_price)}
                          {item.price_source && (
                            <p className="mt-1 font-sans text-xs text-muted">
                              {item.price_source === 'agreement'
                                ? 'Agreed price'
                                : 'Standard price'}
                              {item.price_tier_min_qty
                                ? ` · ${item.price_tier_min_qty}+ units`
                                : ''}
                            </p>
                          )}
                        </Td>
                        <Td align="right" mono className="font-medium">
                          {formatPeso(item.total)}
                        </Td>
                        <Td align="right" mono>
                          {formatDecimal(item.quantity_delivered)}
                        </Td>
                        <Td align="right" mono>
                          {item.delivery_date}
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className={cn(trCls, 'bg-subtle')}>
                      <Td align="right" mono className="text-muted text-2xs uppercase" colSpan={4}>
                        Subtotal
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(data.subtotal)}
                      </Td>
                      <Td colSpan={2} />
                    </tr>
                    <tr className={cn(trCls, 'bg-subtle')}>
                      <Td align="right" mono className="text-muted text-2xs uppercase" colSpan={4}>
                        VAT
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(data.vat_amount)}
                      </Td>
                      <Td colSpan={2} />
                    </tr>
                    <tr className={cn(trCls, 'bg-subtle')}>
                      <Td
                        align="right"
                        mono
                        className="text-muted text-2xs uppercase font-medium"
                        colSpan={4}
                      >
                        Total
                      </Td>
                      <Td align="right" mono className="font-medium text-primary">
                        {formatPeso(data.total_amount)}
                      </Td>
                      <Td colSpan={2} />
                    </tr>
                  </tfoot>
                </table>
              </div>
            </Panel>
          </div>

          <div className="space-y-4">
            <Panel title="Linked records">
              {/* Sprint 6 audit §3.2: render LinkedRecords with the actual chain
 context — MRP plan + draft/in-progress WOs eager-loaded by
 SalesOrderService::show(). */}
              <LinkedRecords
                groups={[
                  ...(data.mrp_plan
                    ? [
                        {
                          label: 'MRP Plan',
                          items: [
                            {
                              id: data.mrp_plan.mrp_plan_no,
                              href: `/mrp/plans/${data.mrp_plan.id}`,
                              meta: `v${data.mrp_plan.version} · ${data.mrp_plan.draft_wo_count} WOs · ${data.mrp_plan.shortages_found} shortages`,
                              chip: {
                                variant:
                                  data.mrp_plan.status === 'active'
                                    ? ('success' as const)
                                    : data.mrp_plan.status === 'cancelled'
                                      ? ('danger' as const)
                                      : ('neutral' as const),
                                text: data.mrp_plan.status_label ?? data.mrp_plan.status,
                              },
                            },
                          ],
                        },
                      ]
                    : []),
                  ...(data.work_orders && data.work_orders.length > 0
                    ? [
                        {
                          label: 'Work Orders',
                          items: data.work_orders.map((wo) => ({
                            id: wo.wo_number,
                            href: `/production/work-orders/${wo.id}`,
                            meta: `${wo.product?.part_number ?? ''} · ${formatInt(wo.quantity_produced)} / ${formatInt(wo.quantity_target)}`,
                            chip: {
                              variant:
                                wo.status === 'completed' || wo.status === 'closed'
                                  ? ('success' as const)
                                  : wo.status === 'in_progress'
                                    ? ('info' as const)
                                    : wo.status === 'paused'
                                      ? ('warning' as const)
                                      : wo.status === 'cancelled'
                                        ? ('danger' as const)
                                        : ('neutral' as const),
                              text: wo.status_label ?? wo.status.replace('_', ' '),
                            },
                          })),
                        },
                      ]
                    : []),
                  ...(data.inspections && data.inspections.length > 0
                    ? [
                        {
                          label: 'Quality inspections',
                          items: data.inspections.map((inspection) => ({
                            id: inspection.inspection_number,
                            href: `/quality/inspections/${inspection.id}`,
                            meta: inspection.stage_label ?? inspection.stage.replace('_', ' '),
                            chip: {
                              variant:
                                inspection.status === 'passed'
                                  ? ('success' as const)
                                  : inspection.status === 'failed'
                                    ? ('danger' as const)
                                    : inspection.status === 'in_progress'
                                      ? ('info' as const)
                                      : ('neutral' as const),
                              text: inspection.status_label ?? inspection.status.replace('_', ' '),
                            },
                          })),
                        },
                      ]
                    : []),
                  ...(data.deliveries && data.deliveries.length > 0
                    ? [
                        {
                          label: 'Deliveries',
                          items: data.deliveries.map((delivery) => ({
                            id: delivery.delivery_number,
                            href: `/supply-chain/deliveries/${delivery.id}`,
                            meta: delivery.scheduled_date ?? undefined,
                            chip: {
                              variant:
                                delivery.status === 'confirmed' || delivery.status === 'delivered'
                                  ? ('success' as const)
                                  : delivery.status === 'in_transit'
                                    ? ('info' as const)
                                    : delivery.status === 'cancelled'
                                      ? ('danger' as const)
                                      : ('neutral' as const),
                              text: delivery.status_label ?? delivery.status.replace('_', ' '),
                            },
                          })),
                        },
                      ]
                    : []),
                  ...(data.invoices && data.invoices.length > 0
                    ? [
                        {
                          label: 'Invoices',
                          items: data.invoices.map((invoice) => ({
                            id: invoice.invoice_number,
                            href: `/accounting/invoices/${invoice.id}`,
                            meta: `${formatPeso(invoice.total_amount)} · balance ${formatPeso(invoice.balance)}`,
                            chip: {
                              variant:
                                invoice.status === 'paid'
                                  ? ('success' as const)
                                  : invoice.status === 'overdue'
                                    ? ('danger' as const)
                                    : invoice.status === 'partial'
                                      ? ('warning' as const)
                                      : ('neutral' as const),
                              text: invoice.status_label ?? invoice.status.replace('_', ' '),
                            },
                          })),
                        },
                      ]
                    : []),
                ]}
              />
            </Panel>
            <Panel title="Activity">
              {activityItems.length > 0 ? (
                <ActivityStream items={activityItems} />
              ) : (
                <p className="text-xs text-muted">No status changes have been recorded.</p>
              )}
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
              {' · '}
              {data.item_count} line items{' · '}
              <span className="font-mono">{formatPeso(data.total_amount)}</span>
            </p>
            <p className="text-muted">Confirming this order will automatically:</p>
            <ul className="list-none space-y-1 text-muted">
              <li className="flex items-start gap-1.5">
                <LuArrowRight size={12} className="mt-0.5 shrink-0 text-muted" aria-hidden="true" />
                Run MRP and check material availability
              </li>
              <li className="flex items-start gap-1.5">
                <LuArrowRight size={12} className="mt-0.5 shrink-0 text-muted" aria-hidden="true" />
                Create Work Orders for all {data.item_count} lines
              </li>
              <li className="flex items-start gap-1.5">
                <LuArrowRight size={12} className="mt-0.5 shrink-0 text-muted" aria-hidden="true" />
                Schedule production on available machines
              </li>
              <li className="flex items-start gap-1.5">
                <LuArrowRight size={12} className="mt-0.5 shrink-0 text-muted" aria-hidden="true" />
                Reserve required materials in inventory
              </li>
              <li className="flex items-start gap-1.5">
                <LuArrowRight size={12} className="mt-0.5 shrink-0 text-muted" aria-hidden="true" />
                Notify Production, Warehouse, and PPC teams
              </li>
            </ul>
          </div>
        }
        confirmLabel="Confirm & Start Chain"
        variant="primary"
        pending={confirm.isPending}
      />

      <ConfirmDialog
        isOpen={cancelOpen}
        onClose={() => {
          setCancelOpen(false);
          setCancelReason('');
        }}
        onConfirm={() => cancel.mutate(cancelReason.trim() || undefined)}
        title="Cancel sales order?"
        description={
          <div className="space-y-3">
            <p>
              <span className="font-mono font-medium text-primary">{data.so_number}</span> will be
              marked as cancelled. This cannot be undone.
            </p>
            <Textarea
              label="Cancellation reason (optional)"
              value={cancelReason}
              onChange={(event) => setCancelReason(event.target.value)}
              maxLength={500}
              placeholder="Record why this order is being cancelled"
            />
          </div>
        }
        confirmLabel="Cancel order"
        variant="danger"
        pending={cancel.isPending}
      />

      {/* 2026-08-08 — finalize the auto-created draft invoice from the SO. */}
      <ConfirmDialog
        isOpen={!!finalizeInvoiceId}
        onClose={() => setFinalizeInvoiceId(null)}
        onConfirm={() => {
          if (finalizeInvoiceId) finalizeInvoice.mutate(finalizeInvoiceId);
        }}
        title="Finalize draft invoice?"
        description="Finalizing locks the invoice number, posts the AR/revenue journal entry, and flips the invoice to Finalized. Review the auto-created amounts before posting."
        confirmLabel="Finalize invoice"
        pending={finalizeInvoice.isPending}
      />

      <ChainResultModal chainResult={chainResult} onClose={() => setChainResult(null)} />
    </div>
  );
}
