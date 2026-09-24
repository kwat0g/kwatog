import { useQuery } from '@tanstack/react-query';
import { supplierActivityApi } from '@/api/b2b/supplier-activity';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatDate } from '@/lib/formatDate';
import { SupplierDocumentsTable } from '@/pages/purchasing/purchase-orders/components/SupplierActivityPanel';

/**
 * The supplier's shipping documents for this receipt's PO — incoming QC checks
 * the certificate of analysis here before accepting resin. CoA rows sort first.
 */
export function GrnSupplierDocumentsPanel({ grnId }: { grnId: string }) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'grn', grnId, 'supplier-documents'],
    queryFn: () => supplierActivityApi.forGoodsReceipt(grnId),
  });

  if (isLoading) {
    return (
      <Panel title="Supplier documents">
        <SkeletonTable columns={3} rows={2} />
      </Panel>
    );
  }

  if (isError || !data) {
    return (
      <Panel
        title="Supplier documents"
        actions={
          <Button variant="secondary" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        }
      >
        <p className="text-sm text-muted">Could not load the supplier&apos;s documents.</p>
      </Panel>
    );
  }

  const shipment = data.shipment;

  return (
    <Panel
      title="Supplier documents"
      meta={
        shipment ? (
          <span className="font-mono">
            {shipment.carrier ?? '—'} · {shipment.tracking_number ?? '—'}
            {shipment.estimated_arrival ? ` · ETA ${formatDate(shipment.estimated_arrival)}` : ''}
          </span>
        ) : undefined
      }
      noPadding
    >
      <SupplierDocumentsTable documents={data.documents} />
    </Panel>
  );
}
