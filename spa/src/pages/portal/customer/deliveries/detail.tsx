import { useQuery } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';

export default function CustomerDeliveryDetailPage() {
  const { id } = useParams<{ id: string }>();

  const { data: delivery, isLoading } = useQuery({
    queryKey: ['portal', 'customer', 'delivery', id],
    queryFn: () => customerPortalApi.getDelivery(id!),
    enabled: !!id,
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;
  if (!delivery) return <EmptyState icon="file-x" title="Delivery not found" />;

  return (
    <div className="space-y-4 max-w-4xl">
      <div className="flex items-center gap-3">
        <Link to="/portal/customer/deliveries" className="text-muted hover:text-primary p-1 -ml-1">
          <ArrowLeft size={16} />
        </Link>
        <div>
          <h2 className="text-sm font-semibold">{delivery.delivery_number}</h2>
          <p className="text-2xs text-muted">{delivery.delivered_at ?? '—'}</p>
        </div>
      </div>

      {/* Items */}
      {delivery.items && delivery.items.length > 0 && (
        <Panel title={`Items (${delivery.items.length})`}>
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Part #</th>
                <th className="text-left py-2 px-3 font-medium">Description</th>
                <th className="text-right py-2 px-3 font-medium">Qty Delivered</th>
              </tr>
            </thead>
            <tbody>
              {delivery.items.map((item, i) => (
                <tr key={i} className="border-b border-border/50">
                  <td className="py-2 px-3 font-mono text-muted">{item.part_number}</td>
                  <td className="py-2 px-3">{item.name}</td>
                  <td className="py-2 px-3 text-right">{item.quantity_delivered}</td>
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
              <div key={proof.id} className="border border-border rounded-lg p-3">
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
