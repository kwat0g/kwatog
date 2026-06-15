import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Chip } from '@/components/ui/Chip';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

export default function CustomerDashboardPage() {
  const { data: dashboard, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'dashboard'],
    queryFn: () => customerPortalApi.dashboard(),
  });

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonBlock key={i} className="h-24 rounded-lg" />
          ))}
        </div>
        <SkeletonBlock className="h-48 rounded-lg" />
        <SkeletonBlock className="h-48 rounded-lg" />
      </div>
    );
  }

  if (isError) return <EmptyState icon="alert-circle" title="Failed to load dashboard" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  return (
    <div className="space-y-4 max-w-5xl">
      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <StatCard
          label="Open Orders"
          value={dashboard?.open_so_count ?? 0}
          helper="Pending fulfillment"
        />
        <StatCard
          label="Pending Deliveries"
          value={dashboard?.pending_delivery_count ?? 0}
          helper="Awaited deliveries"
        />
        <StatCard
          label="Open Invoices"
          value={dashboard?.open_invoice_count ?? 0}
          helper="Invoices due"
        />
        <StatCard
          label="Outstanding"
          value={dashboard?.total_outstanding ? `₱${Number(dashboard.total_outstanding).toLocaleString()}` : '₱0'}
          helper="Total balance"
        />
      </div>

      {/* Recent Orders */}
      <Panel title="Recent Orders" actions={
        <Link to="/portal/customer/orders" className="text-2xs text-accent hover:underline flex items-center gap-1">
          View all <ArrowRight size={11} />
        </Link>
      }>
        {dashboard?.recent_orders && dashboard.recent_orders.length > 0 ? (
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
              {dashboard.recent_orders.map((order) => (
                <tr key={order.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                  <td className="py-2 px-3">
                    <Link to={`/portal/customer/orders/${order.id}`} className="font-mono text-accent hover:underline">
                      {order.so_number}
                    </Link>
                  </td>
                  <td className="py-2 px-3 text-muted">{order.date ?? '—'}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(order.total_amount).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right">
                    <Chip variant="neutral">{order.status}</Chip>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState icon="package" title="No orders yet" />
        )}
      </Panel>

      {/* Recent Invoices */}
      <Panel title="Recent Invoices" actions={
        <Link to="/portal/customer/invoices" className="text-2xs text-accent hover:underline flex items-center gap-1">
          View all <ArrowRight size={11} />
        </Link>
      }>
        {dashboard?.recent_invoices && dashboard.recent_invoices.length > 0 ? (
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Invoice #</th>
                <th className="text-left py-2 px-3 font-medium">Date</th>
                <th className="text-right py-2 px-3 font-medium">Amount</th>
                <th className="text-right py-2 px-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {dashboard.recent_invoices.map((inv) => (
                <tr key={inv.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                  <td className="py-2 px-3">
                    <Link to={`/portal/customer/invoices/${inv.id}`} className="font-mono text-accent hover:underline">
                      {inv.invoice_number}
                    </Link>
                  </td>
                  <td className="py-2 px-3 text-muted">{inv.date ?? '—'}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(inv.total_amount).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right">
                    <Chip variant={inv.status === 'paid' ? 'success' : inv.status === 'overdue' ? 'danger' : 'warning'}>
                      {inv.status}
                    </Chip>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState icon="receipt" title="No invoices yet" />
        )}
      </Panel>
    </div>
  );
}
