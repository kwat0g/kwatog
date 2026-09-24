import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { customerPortalApi } from '@/api/b2b/customer';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { StatCard } from '@/components/ui/StatCard';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [responseType, setResponseType] = useState<'accept' | 'propose' | 'decline'>('accept');
  const [responseNotes, setResponseNotes] = useState('');
  const [proposedDeliveryDate, setProposedDeliveryDate] = useState('');
  const [proposals, setProposals] = useState<Record<string, { quantity: string; unitPrice: string; reason: string }>>({});

  const { data: order, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'order', id],
    queryFn: () => customerPortalApi.getOrder(id!),
    enabled: !!id,
  });

  const { data: chainSteps } = useQuery({
    queryKey: ['portal', 'customer', 'order-chain', id],
    queryFn: () => customerPortalApi.getOrderChain(id!),
    enabled: !!id,
  });

  const respondMutation = useMutation({
    mutationFn: () => customerPortalApi.respondToOrder(id!, {
      type: responseType,
      notes: responseNotes.trim() || undefined,
      proposed_delivery_date: proposedDeliveryDate || undefined,
      ...(responseType === 'propose' ? {
        items: (order?.items ?? []).map((item) => {
          const proposal = proposals[item.id] ?? { quantity: item.quantity, unitPrice: item.unit_price, reason: '' };
          return {
            sales_order_item_id: item.id,
            proposed_quantity: proposal.quantity,
            proposed_unit_price: proposal.unitPrice,
            reason: proposal.reason || undefined,
          };
        }),
      } : {}),
    }),
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'order', id] }),
        queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'orders'] }),
      ]);
      toast.success(response.message);
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Could not submit your order response.'),
  });

  return (
    <div>
      <PageHeader
        title={
          order ? (
            <>
              {order.so_number}{' '}
              <Chip variant={chipVariantForStatus(order.status)}>
                {order.status_label ?? order.status.replace(/_/g, ' ')}
              </Chip>
            </>
          ) : (
            'Sales order'
          )
        }
        subtitle={order?.date ? formatDate(order.date) : undefined}
        backTo="/portal/customer/orders"
        backLabel="Orders"
        bottom={chainSteps && chainSteps.length > 0 ? <ChainHeader steps={chainSteps} className="mt-2" /> : null}
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load order"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && !order && (
          <EmptyState icon="file-question" title="Order not found" />
        )}

        {!isLoading && !isError && order && (
          <>
            <KpiGrid count={3}>
              <StatCard label="Total Amount" value={formatPeso(order.total_amount)} />
              <StatCard
                label="Payment Terms"
                value={order.payment_terms_days != null ? `${order.payment_terms_days} days` : '—'}
              />
              <StatCard label="Delivery Terms" value={order.delivery_terms ?? '—'} />
            </KpiGrid>

            {order.notes && (
              <Panel title="Notes">
                <p className="text-sm whitespace-pre-wrap">{order.notes}</p>
              </Panel>
            )}

            {order.latest_response && <Panel title="Latest response from your account">
              <p className="text-sm">{order.latest_response.type.replace(/_/g, ' ')} · {order.latest_response.status.replace(/_/g, ' ')}</p>
              {order.latest_response.notes && <p className="mt-1 text-sm text-muted whitespace-pre-wrap">{order.latest_response.notes}</p>}
              {order.latest_response.resolution_notes && <p className="mt-2 text-sm text-muted whitespace-pre-wrap">Sales response: {order.latest_response.resolution_notes}</p>}
            </Panel>}

            {order.capabilities?.can_respond && <Panel title="Respond to this order">
              <div className="grid gap-3 sm:grid-cols-2">
                <Select label="Response" value={responseType} onChange={(event) => setResponseType(event.target.value as typeof responseType)}>
                  <option value="accept">Accept as quoted</option>
                  <option value="propose">Propose changes</option>
                  <option value="decline">Decline order</option>
                </Select>
                <Input label="Proposed delivery date" type="date" value={proposedDeliveryDate} onChange={(event) => setProposedDeliveryDate(event.target.value)} />
              </div>
              {responseType === 'propose' && <div className="mt-3 space-y-3">
                {(order.items ?? []).map((item) => {
                  const proposal = proposals[item.id] ?? { quantity: item.quantity, unitPrice: item.unit_price, reason: '' };
                  return <div key={item.id} className="rounded-md border border-default p-3">
                    <p className="text-sm font-medium">{item.part_number} · {item.name}</p>
                    <p className="text-xs text-muted">Current quantity {item.quantity} · unit price {formatPeso(item.unit_price)}</p>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                      <Input label="Proposed quantity" inputMode="decimal" value={proposal.quantity} onChange={(event) => setProposals((current) => ({ ...current, [item.id]: { ...proposal, quantity: event.target.value } }))} />
                      <Input label="Proposed unit price" inputMode="decimal" prefix="₱" value={proposal.unitPrice} onChange={(event) => setProposals((current) => ({ ...current, [item.id]: { ...proposal, unitPrice: event.target.value } }))} />
                      <Input label="Reason" value={proposal.reason} onChange={(event) => setProposals((current) => ({ ...current, [item.id]: { ...proposal, reason: event.target.value } }))} />
                    </div>
                  </div>;
                })}
              </div>}
              <label className="mt-3 block text-xs text-muted">Notes<textarea className="mt-1 min-h-20 w-full rounded-md border border-default bg-canvas p-3 text-sm text-primary" value={responseNotes} onChange={(event) => setResponseNotes(event.target.value)} /></label>
              <Button className="mt-3" variant="primary" disabled={respondMutation.isPending || (responseType === 'propose' && !order.items?.length)} loading={respondMutation.isPending} onClick={() => respondMutation.mutate()}>
                {responseType === 'accept' ? 'Accept order' : responseType === 'decline' ? 'Decline order' : 'Submit proposal'}
              </Button>
            </Panel>}

            <Panel title="Line items" meta={String(order.items?.length ?? 0)} noPadding>
              {order.items && order.items.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Part #</Th>
                        <Th>Description</Th>
                        <Th align="right">Qty</Th>
                        <Th align="right">Unit Price</Th>
                        <Th align="right">Total</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {order.items.map((item) => (
                        <tr key={item.id} className={trCls}>
                          <Td mono className="text-muted">{item.part_number}</Td>
                          <Td>{item.name}</Td>
                          <Td align="right" mono>{item.quantity}</Td>
                          <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
                          <Td align="right" mono>{formatPeso(item.total)}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState icon="package" title="No items" />
              )}
            </Panel>

            {order.work_orders && order.work_orders.length > 0 && (
              <Panel title="Work Orders" meta={String(order.work_orders.length)} noPadding>
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>WO #</Th>
                        <Th align="right">Target</Th>
                        <Th align="right">Produced</Th>
                        <Th>Start</Th>
                        <Th>Status</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {order.work_orders.map((wo) => (
                        <tr key={wo.id} className={trCls}>
                          <Td mono>{wo.wo_number}</Td>
                          <Td align="right" mono>{wo.quantity_target}</Td>
                          <Td align="right" mono>{wo.quantity_produced}</Td>
                          <Td className="text-muted">{wo.planned_start ? formatDate(wo.planned_start) : '—'}</Td>
                          <Td>
                            <Chip variant={chipVariantForStatus(wo.status)}>{wo.status_label ?? wo.status}</Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            )}
          </>
        )}
      </div>
    </div>
  );
}
