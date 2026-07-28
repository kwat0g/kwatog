import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerDeliveriesPage() {
  const { data: deliveries, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'deliveries'],
    queryFn: () => customerPortalApi.listDeliveries(),
    placeholderData: (prev) => prev,
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-md" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load deliveries" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  return (
    <Panel title="Deliveries">
      {deliveries && deliveries.length > 0 ? (
        <table className={tableCls}>
          <thead>
            <tr className={theadTrCls}>
              <Th>DR #</Th>
              <Th>Delivery Date</Th>
              <Th align="right">Status</Th>
            </tr>
          </thead>
          <tbody>
            {deliveries.map((d) => (
              <tr key={d.id} className={trCls}>
                <Td>
                  <Link to={`/portal/customer/deliveries/${d.id}`} className="font-mono text-accent hover:underline">
                    {d.delivery_number}
                  </Link>
                </Td>
                <Td className="text-muted">{d.delivered_at ?? '—'}</Td>
                <Td align="right" mono>
                  <Chip variant={chipVariantForStatus(d.status)}>{d.status}</Chip>
                </Td>
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
