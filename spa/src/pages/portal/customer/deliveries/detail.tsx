import { useQuery } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerDeliveryDetailPage() {
  const { id } = useParams<{ id: string }>();

  const { data: delivery, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'delivery', id],
    queryFn: () => customerPortalApi.getDelivery(id!),
    enabled: !!id,
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-md" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load delivery" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!delivery) return <EmptyState icon="file-x" title="Delivery not found" />;

  return (
    <div className="space-y-4 max-w-4xl">
      <div className="flex items-center gap-3">
        <Link to="/portal/customer/deliveries" className="text-muted hover:text-primary p-1 -ml-1">
          <ArrowLeft size={16} />
        </Link>
        <div>
          <h2 className="text-sm font-medium">{delivery.delivery_number}</h2>
          <p className="text-2xs text-muted">{delivery.delivered_at ?? '—'}</p>
        </div>
      </div>

      {/* Items */}
      {delivery.items && delivery.items.length > 0 && (
        <Panel title={`Items (${delivery.items.length})`}>
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Part #</Th>
                <Th>Description</Th>
                <Th align="right">Qty Delivered</Th>
              </tr>
            </thead>
            <tbody>
              {delivery.items.map((item, i) => (
                <tr key={i} className={trCls}>
                  <Td mono className="text-muted">{item.part_number}</Td>
                  <Td>{item.name}</Td>
                  <Td align="right" mono>{item.quantity_delivered}</Td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {/* Proofs */}
      {delivery.proofs && delivery.proofs.length > 0 && (
        <Panel title="Delivery Proofs">
          <div className="grid grid-cols-2 gap-3">
            {delivery.proofs.map((proof) => (
              <div key={proof.id} className="border border-border rounded-md p-3">
                <p className="text-xs font-medium capitalize mb-1">{proof.proof_type}</p>
                {proof.view_url ? (
                  <a href={proof.view_url} target="_blank" rel="noopener noreferrer"
                    className="text-2xs text-accent hover:underline block truncate">
                    {proof.file_name}
                  </a>
                ) : (
                  <p className="text-2xs text-muted">{proof.file_name}</p>
                )}
                {proof.notes && <p className="text-2xs text-muted mt-1">{proof.notes}</p>}
              </div>
            ))}
          </div>
        </Panel>
      )}
    </div>
  );
}
