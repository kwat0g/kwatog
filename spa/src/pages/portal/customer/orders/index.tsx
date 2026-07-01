import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { formatPeso } from '@/lib/formatNumber';

export default function CustomerOrdersPage() {
  const { data: orders, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'orders'],
    queryFn: () => customerPortalApi.listOrders(),
    placeholderData: (prev) => prev,
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load orders" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  return (
    <Panel title="My Orders">
      {orders && orders.length > 0 ? (
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-border text-muted">
              <th className="text-left py-2 px-3 font-medium">Order #</th>
              <th className="text-left py-2 px-3 font-medium">Date</th>
              <th className="text-right py-2 px-3 font-medium">Amount</th>
              <th className="text-right py-2 px-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {orders.map((order) => (
              <tr key={order.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                <td className="py-2.5 px-3">
                  <Link to={`/portal/customer/orders/${order.id}`} className="font-mono text-accent hover:underline font-medium">
                    {order.so_number}
                  </Link>
                </td>
                <td className="py-2.5 px-3 text-muted">{order.date ?? '—'}</td>
                <td className="py-2.5 px-3 text-right font-mono">{formatPeso(order.total_amount)}</td>

                <td className="py-2.5 px-3 text-right">
                  <Chip variant={chipVariantForStatus(order.status)}>{order.status.replace(/_/g, ' ')}</Chip>
                </td>
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
