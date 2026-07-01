import { useQuery } from '@tanstack/react-query';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';

export default function SupplierDeliveriesPage() {
  const { data: deliveries, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'deliveries'],
    queryFn: () => supplierPortalApi.listDeliveries(),
    placeholderData: (prev) => prev,
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
              <th className="text-left py-2 px-3 font-medium">Date</th>
              <th className="text-right py-2 px-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {deliveries.map((d) => (
              <tr key={d.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                <td className="py-2.5 px-3 font-mono">{d.delivery_number}</td>
                <td className="py-2.5 px-3 text-muted">{d.delivered_at ?? '—'}</td>
                <td className="py-2.5 px-3 text-right">
                  <Chip variant={chipVariantForStatus(d.status)}>{d.status}</Chip>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <EmptyState icon="truck" title="No deliveries" />
      )}
    </Panel>
  );
}
