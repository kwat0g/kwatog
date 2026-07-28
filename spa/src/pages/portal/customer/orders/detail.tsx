import { useQuery } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
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

  if (isLoading) return <SkeletonBlock className="h-96 rounded-md" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load order" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!order) return <EmptyState icon="file-question" title="Order not found" />;

  return (
    <div className="space-y-4 max-w-4xl">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Link to="/portal/customer/orders" className="text-muted hover:text-primary p-1 -ml-1">
            <ArrowLeft size={16} />
          </Link>
          <div>
            <h2 className="text-sm font-medium">{order.so_number}</h2>
            <p className="text-2xs text-muted">{order.date ?? '—'}</p>
          </div>
        </div>
        <Chip variant={chipVariantForStatus(order.status)}>{order.status.replace(/_/g, ' ')}</Chip>
      </div>

      {/* Order-to-Cash Chain Visualization */}
      {chainSteps && chainSteps.length > 0 && (
        <Panel title="Order Status" bodyClassName="py-4 px-5">
          <ChainHeader steps={chainSteps} />
        </Panel>
      )}

      {/* Items */}
      <Panel title={`Items (${order.items?.length ?? 0})`}>
        {order.items && order.items.length > 0 ? (
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
                  <Td align="right" mono>{formatPeso(item.total_price)}</Td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState icon="package" title="No items" />
        )}
      </Panel>

      {/* Work Orders */}
      {order.work_orders && order.work_orders.length > 0 && (
        <Panel title="Work Orders">
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>WO #</Th>
                <Th align="right">Target</Th>
                <Th align="right">Produced</Th>
                <Th>Start</Th>
                <Th align="right">Status</Th>
              </tr>
            </thead>
            <tbody>
              {order.work_orders.map((wo) => (
                <tr key={wo.id} className={trCls}>
                  <Td mono>{wo.wo_number}</Td>
                  <Td align="right" mono>{wo.quantity_target}</Td>
                  <Td align="right" mono>{wo.quantity_produced}</Td>
                  <Td className="text-muted">{wo.planned_start ?? '—'}</Td>
                  <Td align="right" mono>
                    <Chip variant={chipVariantForStatus(wo.status)}>{wo.status}</Chip>
                  </Td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  );
}
