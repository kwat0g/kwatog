import { useQuery } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

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

  if (isLoading) return <SkeletonBlock className="h-96 rounded-lg" />;
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
            <h2 className="text-sm font-semibold">{order.so_number}</h2>
            <p className="text-2xs text-muted">{order.date ?? '—'}</p>
          </div>
        </div>
        <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium uppercase ${
          order.status === 'confirmed' ? 'bg-accent/10 text-accent' :
          order.status === 'shipped' ? 'bg-info/10 text-info' :
          order.status === 'delivered' ? 'bg-success/10 text-success' :
          'bg-subtle text-muted'
        }`}>{order.status.replace(/_/g, ' ')}</span>
      </div>

      {/* Order-to-Cash Chain Visualization */}
      {chainSteps && chainSteps.length > 0 && (
        <Panel title="Order Status" bodyClassName="py-4 px-6">
          <ChainHeader steps={chainSteps} />
        </Panel>
      )}

      {/* Items */}
      <Panel title={`Items (${order.items?.length ?? 0})`}>
        {order.items && order.items.length > 0 ? (
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Part #</th>
                <th className="text-left py-2 px-3 font-medium">Description</th>
                <th className="text-right py-2 px-3 font-medium">Qty</th>
                <th className="text-right py-2 px-3 font-medium">Unit Price</th>
                <th className="text-right py-2 px-3 font-medium">Total</th>
              </tr>
            </thead>
            <tbody>
              {order.items.map((item) => (
                <tr key={item.id} className="border-b border-border/50">
                  <td className="py-2 px-3 font-mono text-muted">{item.part_number}</td>
                  <td className="py-2 px-3">{item.name}</td>
                  <td className="py-2 px-3 text-right">{item.quantity}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(item.unit_price).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(item.total_price).toLocaleString()}</td>
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
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">WO #</th>
                <th className="text-right py-2 px-3 font-medium">Target</th>
                <th className="text-right py-2 px-3 font-medium">Produced</th>
                <th className="text-left py-2 px-3 font-medium">Start</th>
                <th className="text-right py-2 px-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {order.work_orders.map((wo) => (
                <tr key={wo.id} className="border-b border-border/50">
                  <td className="py-2 px-3 font-mono">{wo.wo_number}</td>
                  <td className="py-2 px-3 text-right">{wo.quantity_target}</td>
                  <td className="py-2 px-3 text-right">{wo.quantity_produced}</td>
                  <td className="py-2 px-3 text-muted">{wo.planned_start ?? '—'}</td>
                  <td className="py-2 px-3 text-right">
                    <span className="inline-block px-2 py-0.5 rounded-full text-2xs font-medium bg-subtle text-muted uppercase">{wo.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  );
}
