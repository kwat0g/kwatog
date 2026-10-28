import { DeliveryNegotiationHistory } from '@/components/crm/DeliveryNegotiationHistory';
import { OrderAgreementsPanel } from '@/components/crm/OrderAgreementsPanel';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
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
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Textarea } from '@/components/ui/Textarea';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [deliveryDecision, setDeliveryDecision] = useState<{
    decision: 'accept' | 'reject';
    proposalId: string;
    date: string | null;
  } | null>(null);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [responseType, setResponseType] = useState<'accept' | 'propose' | 'decline'>('accept');
  const [responseNotes, setResponseNotes] = useState('');
  const [proposedDeliveryDate, setProposedDeliveryDate] = useState('');
  const [proposals, setProposals] = useState<
    Record<string, { quantity: string; unitPrice: string; reason: string }>
  >({});

  const {
    data: order,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'customer', 'order', id],
    queryFn: () => customerPortalApi.getOrder(id!),
    enabled: !!id,
    refetchInterval: (query) => query.state.data && !['closed', 'cancelled'].includes(query.state.data.status) ? 30_000 : false,
  });

  const { data: chainSteps, isError: chainError, refetch: refetchChain } = useQuery({
    queryKey: ['portal', 'customer', 'order-chain', id],
    queryFn: () => customerPortalApi.getOrderChain(id!),
    enabled: !!id,
    refetchInterval: order && !['closed', 'cancelled'].includes(order.status) ? 30_000 : false,
  });

  const cancelMutation = useMutation({
    mutationFn: () => customerPortalApi.cancelOrder(id!, cancelReason.trim() || undefined),
    onSuccess: async (result) => {
      setCancelOpen(false);
      await queryClient.invalidateQueries({ queryKey: ['portal', 'customer'] });
      toast.success(result.message);
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Could not cancel order.'),
  });

  const deliveryResponse = useMutation({
    mutationFn: ({ decision, proposalId }: { decision: 'accept' | 'reject'; proposalId: string }) =>
      customerPortalApi.respondToDeliveryProposal(id!, proposalId, decision),
    onSuccess: async (_, { decision }) => {
      setDeliveryDecision(null);
      await queryClient.invalidateQueries({ queryKey: ['portal', 'customer'] });
      toast.success(
        decision === 'accept'
          ? 'Proposed delivery date accepted.'
          : 'Proposed delivery date declined. Your current schedule is unchanged.',
      );
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Could not respond to the delivery proposal.'),
  });

  const respondMutation = useMutation({
    mutationFn: () =>
      customerPortalApi.respondToOrder(id!, {
        type: responseType,
        notes: responseNotes.trim() || undefined,
        proposed_delivery_date: proposedDeliveryDate || undefined,
        ...(responseType === 'propose'
          ? {
              items: (order?.items ?? []).map((item) => {
                const proposal = proposals[item.id] ?? {
                  quantity: item.quantity,
                  unitPrice: item.unit_price,
                  reason: '',
                };
                return {
                  sales_order_item_id: item.id,
                  proposed_quantity: proposal.quantity,
                  proposed_unit_price: proposal.unitPrice,
                  reason: proposal.reason || undefined,
                };
              }),
            }
          : {}),
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
        actions={
          order?.capabilities?.can_edit || order?.capabilities?.can_cancel ? (
            <div className="flex gap-2">
              {order.capabilities.can_edit && (
                <Button variant="secondary" onClick={() => navigate(`/portal/customer/orders/${id}/edit`)}>
                  Edit order
                </Button>
              )}
              {order.capabilities.can_cancel && (
                <Button variant="danger" onClick={() => setCancelOpen(true)}>Cancel order</Button>
              )}
            </div>
          ) : undefined
        }
        bottom={
          chainSteps && chainSteps.length > 0 ? (
            <ChainHeader steps={chainSteps} className="mt-2" />
          ) : null
        }
      />
      {chainError && <div role="alert" className="px-5 py-3 text-sm text-danger-fg">
        Order progress could not be loaded. <Button variant="secondary" size="sm" onClick={() => refetchChain()}>Retry order progress</Button>
      </div>}
      {order?.latest_response?.type === 'cancellation_request' && (
        <div role="status" className="mx-5 mt-3 rounded-md border border-default bg-surface px-4 py-3 text-sm">
          <p className="flex flex-wrap items-center gap-2 font-medium">Cancellation request <Chip variant={chipVariantForStatus(order.latest_response.status)}>{order.latest_response.status}</Chip></p>
          <p className="mt-1 text-muted">
            {order.latest_response.status === 'pending'
              ? 'Sales is reviewing production and purchasing commitments. Order remains active until review is complete.'
              : order.latest_response.status === 'accepted'
                ? 'Sales approved the cancellation.'
                : order.latest_response.status === 'rejected'
                  ? `Sales could not approve the cancellation${order.latest_response.resolution_notes ? `: ${order.latest_response.resolution_notes}` : '.'}`
                  : 'Review the latest decision with sales.'}
          </p>
          {order.latest_response.notes && <p className="mt-1 whitespace-pre-wrap text-muted">Reason: {order.latest_response.notes}</p>}
        </div>
      )}

      <ConfirmDialog
        isOpen={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title={order && ['confirmed', 'in_production'].includes(order.status) ? 'Cancel or request cancellation?' : 'Cancel this order?'}
        confirmLabel={order && ['confirmed', 'in_production'].includes(order.status) ? 'Continue' : 'Cancel order'}
        cancelLabel="Keep order"
        variant="danger"
        pending={cancelMutation.isPending}
        onConfirm={() => cancelMutation.mutate()}
        description={
          <>
            <p>
              {order && ['confirmed', 'in_production'].includes(order.status)
                ? 'If no production or purchasing commitments exist, order cancels now. Otherwise, sales reviews your request and order stays active until a decision.'
                : 'Cancel this order before sales confirms it.'}
            </p>
            <Textarea
              label="Reason (optional)"
              rows={2}
              maxLength={500}
              containerClassName="mt-3"
              value={cancelReason}
              onChange={(event) => setCancelReason(event.target.value)}
            />
            {cancelMutation.isError && (
              <p role="alert" className="mt-2 text-danger-fg">
                Unable to cancel. Refresh the order to check its latest status.
              </p>
            )}
          </>
        }
      />
      <ConfirmDialog
        isOpen={deliveryDecision !== null}
        onClose={() => setDeliveryDecision(null)}
        title={
          deliveryDecision?.decision === 'accept'
            ? 'Accept this delivery date?'
            : 'Decline this delivery date?'
        }
        confirmLabel={
          deliveryDecision?.decision === 'accept' ? 'Confirm acceptance' : 'Confirm decline'
        }
        cancelLabel="Go back"
        variant={deliveryDecision?.decision === 'reject' ? 'warning' : 'primary'}
        pending={deliveryResponse.isPending}
        onConfirm={() => {
          if (deliveryDecision) deliveryResponse.mutate(deliveryDecision);
        }}
        description={
          <>
            <p>
              {deliveryDecision?.decision === 'accept'
                ? `Schedule the whole order for ${deliveryDecision.date ? formatDate(deliveryDecision.date) : 'the proposed date'}? Sales will still need to confirm the order.`
                : 'Your current schedule stays unchanged. Sales will be notified and can propose another date.'}
            </p>
            {deliveryResponse.isError && (
              <p role="alert" className="mt-2 text-danger-fg">
                {(deliveryResponse.error as Error & { response?: { data?: { message?: string } } })
                  .response?.data?.message ?? 'Could not respond. Try again or refresh the order.'}
              </p>
            )}
          </>
        }
      />
      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load order"
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {!isLoading && !isError && !order && (
          <EmptyState icon="file-question" title="Order not found" />
        )}

        {!isLoading && !isError && order && (
          <>
            <KpiGrid count={3}>
              <StatCard
                label="Total Amount"
                value={formatPeso(order.total_amount)}
                helper={
                  order.vat_amount != null
                    ? Number(order.vat_amount) > 0
                      ? `Includes ${formatPeso(order.vat_amount)} VAT`
                      : 'VAT: 0.00'
                    : undefined
                }
              />
              <StatCard
                label="Payment Terms"
                value={order.payment_terms_days != null ? `${order.payment_terms_days} days` : '—'}
              />
              <StatCard label="Delivery Terms" value={order.delivery_terms ?? '—'} />
            </KpiGrid>

            <Panel title="Delivery schedule">
              <div className="grid gap-3 sm:grid-cols-2 text-sm">
                <div>
                  <p className="text-muted">Required delivery date</p>
                  <p className="mt-1 font-medium">
                    {(
                      order.requested_delivery_dates ?? [
                        ...new Set(
                          order.items
                            ?.map((item) => item.delivery_date)
                            .filter((date): date is string => !!date),
                        ),
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
                      order.delivery_dates ?? [
                        ...new Set(
                          order.items
                            ?.map((item) => item.delivery_date)
                            .filter((date): date is string => !!date),
                        ),
                      ]
                    )
                      .map((date) => formatDate(date))
                      .join(', ') || '—'}
                  </p>
                </div>
              </div>
              {order.latest_response?.type === 'delivery_date' && (
                <div className="mt-4 border-t border-default pt-3 text-sm">
                  <p>
                    Sales proposed:{' '}
                    <strong>
                      {order.latest_response.proposed_delivery_date
                        ? formatDate(order.latest_response.proposed_delivery_date)
                        : '—'}
                    </strong>{' '}
                    · {order.latest_response.status}
                  </p>
                  <p className="mt-1 whitespace-pre-wrap text-muted">
                    {order.latest_response.notes}
                  </p>
                  {order.capabilities?.can_respond_delivery && (
                    <>
                      <p className="mt-2 text-muted">
                        Accept to schedule the whole order on this date. Products, quantities and
                        prices stay unchanged.
                      </p>
                      <div className="mt-3 flex flex-wrap gap-2">
                        <Button
                          variant="primary"
                          disabled={deliveryResponse.isPending}
                          onClick={() => {
                            deliveryResponse.reset();
                            setDeliveryDecision({
                              decision: 'accept',
                              proposalId: order.latest_response!.id,
                              date: order.latest_response!.proposed_delivery_date,
                            });
                          }}
                        >
                          Accept proposed date
                        </Button>
                        <Button
                          disabled={deliveryResponse.isPending}
                          onClick={() => {
                            deliveryResponse.reset();
                            setDeliveryDecision({
                              decision: 'reject',
                              proposalId: order.latest_response!.id,
                              date: order.latest_response!.proposed_delivery_date,
                            });
                          }}
                        >
                          Decline proposed date
                        </Button>
                      </div>
                    </>
                  )}
                </div>
              )}
              <DeliveryNegotiationHistory proposals={order.delivery_negotiations} />
              {order.latest_response?.type === 'delivery_date' &&
                order.latest_response.status === 'rejected' && (
                  <p className="mt-3 text-sm text-muted">
                    Sales can propose another date. Your current schedule is unchanged.
                  </p>
                )}
            </Panel>
            <OrderAgreementsPanel orderId={order.id} realm="customer" />
            {order.notes && (
              <Panel title="Notes">
                <p className="text-sm whitespace-pre-wrap">{order.notes}</p>
              </Panel>
            )}

            {order.latest_response && order.latest_response.type !== 'delivery_date' && (
              <Panel title="Latest response from your account">
                <p className="text-sm">
                  {order.latest_response.type.replace(/_/g, ' ')} ·{' '}
                  {order.latest_response.status.replace(/_/g, ' ')}
                </p>
                {order.latest_response.notes && (
                  <p className="mt-1 text-sm text-muted whitespace-pre-wrap">
                    {order.latest_response.notes}
                  </p>
                )}
                {order.latest_response.resolution_notes && (
                  <p className="mt-2 text-sm text-muted whitespace-pre-wrap">
                    Sales response: {order.latest_response.resolution_notes}
                  </p>
                )}
              </Panel>
            )}

            {order.capabilities?.can_respond && (
              <Panel title="Respond to this order">
                <div className="grid gap-3 sm:grid-cols-2">
                  <Select
                    label="Response"
                    value={responseType}
                    onChange={(event) => setResponseType(event.target.value as typeof responseType)}
                  >
                    <option value="accept">Accept as quoted</option>
                    <option value="propose">Propose changes</option>
                    <option value="decline">Decline order</option>
                  </Select>
                  <Input
                    label="Proposed delivery date"
                    type="date"
                    value={proposedDeliveryDate}
                    onChange={(event) => setProposedDeliveryDate(event.target.value)}
                  />
                </div>
                {responseType === 'propose' && (
                  <div className="mt-3 space-y-3">
                    {(order.items ?? []).map((item) => {
                      const proposal = proposals[item.id] ?? {
                        quantity: item.quantity,
                        unitPrice: item.unit_price,
                        reason: '',
                      };
                      return (
                        <div key={item.id} className="rounded-md border border-default p-3">
                          <p className="text-sm font-medium">
                            {item.product?.part_number ?? '—'} · {item.product?.name ?? '—'}
                          </p>
                          <p className="text-xs text-muted">
                            Current quantity {item.quantity} · unit price{' '}
                            {formatPeso(item.unit_price)}
                          </p>
                          <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            <Input
                              label="Proposed quantity"
                              inputMode="decimal"
                              value={proposal.quantity}
                              onChange={(event) =>
                                setProposals((current) => ({
                                  ...current,
                                  [item.id]: { ...proposal, quantity: event.target.value },
                                }))
                              }
                            />
                            <Input
                              label="Proposed unit price"
                              inputMode="decimal"
                              prefix="₱"
                              value={proposal.unitPrice}
                              onChange={(event) =>
                                setProposals((current) => ({
                                  ...current,
                                  [item.id]: { ...proposal, unitPrice: event.target.value },
                                }))
                              }
                            />
                            <Input
                              label="Reason"
                              value={proposal.reason}
                              onChange={(event) =>
                                setProposals((current) => ({
                                  ...current,
                                  [item.id]: { ...proposal, reason: event.target.value },
                                }))
                              }
                            />
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
                <label className="mt-3 block text-xs text-muted">
                  Notes
                  <textarea
                    className="mt-1 min-h-20 w-full rounded-md border border-default bg-canvas p-3 text-sm text-primary"
                    value={responseNotes}
                    onChange={(event) => setResponseNotes(event.target.value)}
                  />
                </label>
                <Button
                  className="mt-3"
                  variant="primary"
                  disabled={
                    respondMutation.isPending ||
                    (responseType === 'propose' && !order.items?.length)
                  }
                  loading={respondMutation.isPending}
                  onClick={() => respondMutation.mutate()}
                >
                  {responseType === 'accept'
                    ? 'Accept order'
                    : responseType === 'decline'
                      ? 'Decline order'
                      : 'Submit proposal'}
                </Button>
              </Panel>
            )}

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
                          <Td mono className="text-muted">
                            {item.product?.part_number ?? '—'}
                          </Td>
                          <Td>{item.product?.name ?? '—'}</Td>
                          <Td align="right" mono>
                            {item.quantity}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(item.unit_price)}
                            {item.price_source && (
                              <p className="mt-1 font-sans text-xs text-muted">
                                {item.price_source === 'agreement'
                                  ? 'Agreed price'
                                  : 'Standard price'}
                              </p>
                            )}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(item.total)}
                          </Td>
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
                          <Td align="right" mono>
                            {wo.quantity_target}
                          </Td>
                          <Td align="right" mono>
                            {wo.quantity_produced}
                          </Td>
                          <Td className="text-muted">
                            {wo.planned_start ? formatDate(wo.planned_start) : '—'}
                          </Td>
                          <Td>
                            <Chip variant={chipVariantForStatus(wo.status)}>
                              {wo.status_label ?? wo.status}
                            </Chip>
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
