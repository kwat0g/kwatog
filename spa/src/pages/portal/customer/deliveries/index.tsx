import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

export default function CustomerDeliveriesPage() {
  const { data: deliveries, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'deliveries'],
    queryFn: () => customerPortalApi.listDeliveries(),
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load deliveries" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  return (
    <Panel title="Deliveries">
      {deliveries && deliveries.length > 0 ? (
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-border text-muted">
              <th className="text-left py-2 px-3 font-medium">DR #</th>
              <th className="text-left py-2 px-3 font-medium">Delivery Date</th>
              <th className="text-right py-2 px-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {deliveries.map((d) => (
              <tr key={d.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                <td className="py-2.5 px-3">
                  <Link to={`/portal/customer/deliveries/${d.id}`} className="font-mono text-accent hover:underline">
                    {d.delivery_number}
                  </Link>
                </td>
                <td className="py-2.5 px-3 text-muted">{d.delivered_at ?? '—'}</td>
                <td className="py-2.5 px-3 text-right">
                  <span className="inline-block px-2 py-0.5 rounded-full text-2xs font-medium bg-subtle text-muted uppercase">
                    {d.status}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <EmptyState icon="truck" title="No deliveries yet" />
      )}
    </Panel>
  );
}
