import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
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
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerOrderDetailPage() {
  const { id } = useParams<{ id: string }>();

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
