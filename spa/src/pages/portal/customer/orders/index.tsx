import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerOrdersPage() {
  const { data: orders, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'orders'],
    queryFn: () => customerPortalApi.listOrders(),
    placeholderData: (prev) => prev,
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-md" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load orders" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  return (
    <Panel title="My Orders">
      {orders && orders.length > 0 ? (
        <table className={tableCls}>
          <thead>
            <tr className={theadTrCls}>
              <Th>Order #</Th>
              <Th>Date</Th>
              <Th align="right">Amount</Th>
              <Th align="right">Status</Th>
            </tr>
          </thead>
          <tbody>
            {orders.map((order) => (
              <tr key={order.id} className={trCls}>
                <Td>
                  <Link to={`/portal/customer/orders/${order.id}`} className="font-mono text-accent hover:underline font-medium">
                    {order.so_number}
                  </Link>
                </Td>
                <Td className="text-muted">{order.date ?? '—'}</Td>
                <Td align="right" mono>{formatPeso(order.total_amount)}</Td>

                <Td align="right" mono>
                  <Chip variant={chipVariantForStatus(order.status)}>{order.status.replace(/_/g, ' ')}</Chip>
                </Td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <EmptyState icon="package" title="No orders" description="Your sales orders will appear here once placed." />
      )}
    </Panel>
  );
}
