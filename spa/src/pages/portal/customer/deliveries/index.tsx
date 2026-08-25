import { useState } from 'react';
import { PortalTable } from '@/components/portal/PortalTable';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { DataTablePagination } from '@/components/ui/DataTablePagination';

export default function CustomerDeliveriesPage() {
  const [page, setPage] = useState(1);
  const {
    data: deliveriesPage,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'customer', 'deliveries', { page }],
    queryFn: () => customerPortalApi.listDeliveries({ page }),
    placeholderData: (prev) => prev,
  });
  const deliveries = deliveriesPage?.data ?? [];

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
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
            {deliveries.length > 0 ? (
              <>
              <PortalTable>
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
                    <tr
                      key={d.id}
                      className={trCls}
                    >
                      <Td>
                        <Link
                          to={`/portal/customer/deliveries/${d.id}`}
                          className="font-mono text-accent hover:underline font-medium"
                        >
                          {d.delivery_number}
                        </Link>
                      </Td>
                      <Td className="text-muted">
                        {d.delivered_at ?? d.scheduled_date ?? '—'}
                        {!d.delivered_at && d.scheduled_date && (
                          <span className="ml-1 text-2xs text-text-subtle">(scheduled)</span>
                        )}
                      </Td>
                      <Td align="right" mono>
                        <Chip variant={chipVariantForStatus(d.status)}>
                          {d.status_label ?? d.status.replace(/_/g, ' ')}
                        </Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
</PortalTable>
              {deliveriesPage?.meta && (
                <div className="px-4 pb-4">
                  <DataTablePagination meta={deliveriesPage.meta} onPageChange={setPage} />
                </div>
              )}
              </>
            ) : (
              <EmptyState
                icon="truck"
                title="No deliveries"
                description="Your deliveries will appear here once dispatched."
              />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
