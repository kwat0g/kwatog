import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';

export default function CustomerOrdersPage() {
  const { data: orders, isLoading } = useQuery({
    queryKey: ['portal', 'customer', 'orders'],
    queryFn: () => customerPortalApi.listOrders(),
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;

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
                <td className="py-2.5 px-3 text-right font-mono">₱{Number(order.total_amount).toLocaleString()}</td>

                <td className="py-2.5 px-3 text-right">
                  <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium uppercase ${
                    order.status === 'draft' ? 'bg-subtle text-muted' :
                    order.status === 'confirmed' ? 'bg-accent/10 text-accent' :
                    order.status === 'in_production' ? 'bg-warning/10 text-warning' :
                    order.status === 'shipped' ? 'bg-info/10 text-info' :
                    order.status === 'delivered' ? 'bg-success/10 text-success' :
                    order.status === 'cancelled' ? 'bg-danger/10 text-danger' :
                    'bg-subtle text-muted'
                  }`}>{order.status.replace(/_/g, ' ')}</span>
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
