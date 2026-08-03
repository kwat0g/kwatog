import { cn } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerDeliveriesPage() {
  const navigate = useNavigate();
  const { data: deliveries, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'deliveries'],
    queryFn: () => customerPortalApi.listDeliveries(),
    placeholderData: (prev) => prev });

  return (
    <div>
      <PageHeader title="Deliveries" subtitle="Shipments dispatched to your sites" />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load deliveries"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
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
                    <tr key={d.id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate(`/portal/customer/deliveries/${d.id}`)}>
                      <Td>
                        
                          {d.delivery_number}
                        
                      </Td>
                      <Td className="text-muted">{d.delivered_at ?? '—'}</Td>
                      <Td align="right" mono>
                        <Chip variant={chipVariantForStatus(d.status)}>{d.status_label ?? d.status.replace(/_/g, ' ')}</Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <EmptyState icon="truck" title="No deliveries" description="Your deliveries will appear here once dispatched." />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
