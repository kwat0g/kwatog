import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuFileText } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatCard } from '@/components/ui/StatCard';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

async function openProof(deliveryId: string, proofId: string, fileName: string) {
  try {
    const blob = await customerPortalApi.viewDeliveryProof(deliveryId, proofId);
    const url = window.URL.createObjectURL(blob);
    const popup = window.open('', '_blank');
    if (popup) {
      popup.opener = null;
      popup.location.href = url;
    }
    window.setTimeout(() => window.URL.revokeObjectURL(url), 60_000);
  } catch {
    toast.error(`Failed to open proof file ${fileName}.`);
  }
}

export default function CustomerDeliveryDetailPage() {
  const { id } = useParams<{ id: string }>();

  const { data: delivery, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'delivery', id],
    queryFn: () => customerPortalApi.getDelivery(id!),
    enabled: !!id,
  });

  return (
    <div>
      <PageHeader
        title={
          delivery ? (
            <>
              {delivery.delivery_number}{' '}
              <Chip variant={chipVariantForStatus(delivery.status)}>
                {delivery.status_label ?? delivery.status.replace(/_/g, ' ')}
              </Chip>
            </>
          ) : (
            'Delivery'
          )
        }
        subtitle={
          delivery?.delivered_at
            ? formatDateTime(delivery.delivered_at)
            : delivery?.scheduled_date
              ? `Scheduled ${formatDate(delivery.scheduled_date)}`
              : undefined
        }
        backTo="/portal/customer/deliveries"
        backLabel="Deliveries"
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load delivery"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && !delivery && (
          <EmptyState icon="file-x" title="Delivery not found" />
        )}

        {!isLoading && !isError && delivery && (
          <>
            <KpiGrid count={4}>
              <StatCard label="Order" value={delivery.sales_order?.so_number ?? '—'} />
              <StatCard
                label="Delivery Date"
                value={
                  delivery.delivered_at
                    ? formatDate(delivery.delivered_at)
                    : delivery.scheduled_date
                      ? formatDate(delivery.scheduled_date)
                      : '—'
                }
                helper={delivery.delivered_at ? undefined : 'Scheduled'}
              />
              <StatCard label="Received By" value={delivery.receiver_name ?? '—'} />
              <StatCard label="Driver" value={delivery.driver?.name ?? '—'} />
            </KpiGrid>

            {delivery.items && delivery.items.length > 0 && (
              <Panel title="Items" meta={String(delivery.items.length)} noPadding>
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Part #</Th>
                        <Th>Description</Th>
                        <Th align="right">Qty Delivered</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {delivery.items.map((item) => (
                        <tr key={item.id} className={trCls}>
                          <Td mono className="text-muted">{item.part_number}</Td>
                          <Td>{item.name}</Td>
                          <Td align="right" mono>{item.quantity_delivered}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            )}

            {delivery.proofs && delivery.proofs.length > 0 && (
              <Panel title="Delivery Proofs" meta={String(delivery.proofs.length)}>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  {delivery.proofs.map((proof) => (
                    <div key={proof.id} className="border border-default rounded-md p-3">
                      <p className="text-xs font-medium capitalize mb-1">{proof.proof_type}</p>
                      {proof.view_url ? (
                        <button
                          type="button"
                          onClick={() => void openProof(id!, proof.id, proof.file_name)}
                          className="text-2xs text-accent hover:underline block truncate w-full text-left cursor-pointer"
                        >
                          {proof.file_name}
                        </button>
                      ) : (
                        <p className="text-2xs text-muted flex items-center gap-1 truncate">
                          <LuFileText size={11} className="shrink-0" />
                          {proof.file_name}
                        </p>
                      )}
                      {proof.notes && <p className="text-2xs text-muted mt-1">{proof.notes}</p>}
                    </div>
                  ))}
                </div>
              </Panel>
            )}
          </>
        )}
      </div>
    </div>
  );
}
