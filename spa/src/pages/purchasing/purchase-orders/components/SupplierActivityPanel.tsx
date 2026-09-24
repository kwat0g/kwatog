import { useQuery } from '@tanstack/react-query';
import { supplierActivityApi } from '@/api/b2b/supplier-activity';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatDate } from '@/lib/formatDate';
import type { SupplierActivityDocument } from '@/types/supplierActivity';

/**
 * Shipping documents a supplier uploaded through the portal. Shared by the PO
 * page (purchasing) and the GRN page (incoming QC reads the CoA there).
 */
export function SupplierDocumentsTable({ documents }: { documents: SupplierActivityDocument[] }) {
  if (documents.length === 0) {
    return <p className="px-3 py-2 text-sm text-muted">No documents uploaded by the supplier.</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className={tableCls}>
        <thead>
          <tr className={theadTrCls}>
            <Th>Type</Th>
            <Th>File</Th>
            <Th>Uploaded</Th>
          </tr>
        </thead>
        <tbody>
          {documents.map((doc) => (
            <tr key={doc.id} className={trCls}>
              <Td>{doc.document_type_label}</Td>
              <Td>
                <button
                  type="button"
                  className="text-accent hover:underline"
                  onClick={() =>
                    void downloadAuthenticatedFile(supplierActivityApi.documentUrl(doc.id), {
                      filename: doc.original_filename,
                      errorMessage: 'Failed to download the supplier document.',
                    })
                  }
                >
                  {doc.original_filename}
                </button>
              </Td>
              <Td mono className="text-muted">
                {doc.uploaded_at ? formatDate(doc.uploaded_at) : '—'}
              </Td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Shipments, documents and delivery plans the supplier reported against this PO. */
export function SupplierActivityPanel({ purchaseOrderId }: { purchaseOrderId: string }) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', purchaseOrderId, 'supplier-activity'],
    queryFn: () => supplierActivityApi.forPurchaseOrder(purchaseOrderId),
  });

  if (isLoading) {
    return (
      <Panel title="Supplier activity">
        <SkeletonTable columns={4} rows={2} />
      </Panel>
    );
  }

  if (isError || !data) {
    return (
      <Panel
        title="Supplier activity"
        actions={
          <Button variant="secondary" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        }
      >
        <p className="text-sm text-muted">Could not load what the supplier reported.</p>
      </Panel>
    );
  }

  const nothingReported =
    data.shipments.length === 0 && data.documents.length === 0 && data.delivery_schedules.length === 0;

  return (
    <Panel title="Supplier activity" meta="Reported through the supplier portal" noPadding>
      {nothingReported ? (
        <p className="px-3 py-2 text-sm text-muted">
          The supplier has not reported shipments, documents or delivery plans yet.
        </p>
      ) : (
        <div className="divide-y divide-default">
          {data.shipments.length > 0 && (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Carrier</Th>
                    <Th>Tracking</Th>
                    <Th>Shipped</Th>
                    <Th>ETA</Th>
                    <Th align="right">Updates</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.shipments.map((shipment) => (
                    <tr key={shipment.id} className={trCls}>
                      <Td>{shipment.carrier ?? '—'}</Td>
                      <Td mono>{shipment.tracking_number ?? '—'}</Td>
                      <Td mono className="text-muted">
                        {shipment.shipped_date ? formatDate(shipment.shipped_date) : '—'}
                      </Td>
                      <Td mono>{shipment.estimated_arrival ? formatDate(shipment.estimated_arrival) : '—'}</Td>
                      <Td align="right" mono className="text-muted">
                        {shipment.updates_count ?? '—'}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {data.documents.length > 0 && <SupplierDocumentsTable documents={data.documents} />}

          {data.delivery_schedules.length > 0 && (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Delivery plan</Th>
                    <Th>Lines</Th>
                    <Th>Status</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.delivery_schedules.map((schedule) => (
                    <tr key={schedule.id} className={trCls}>
                      <Td mono>{schedule.month}</Td>
                      <Td>
                        {schedule.lines.map((line) => (
                          <div key={`${schedule.id}-${line.purchase_order_item_id ?? line.product_name}`}>
                            {line.product_name}{' '}
                            <span className="font-mono tabular-nums text-muted">× {line.quantity}</span>
                          </div>
                        ))}
                        {(schedule.cancel_reason ?? schedule.reject_reason) && (
                          <div className="text-xs text-muted">
                            {schedule.cancel_reason ?? schedule.reject_reason}
                          </div>
                        )}
                      </Td>
                      <Td>
                        <Chip variant={chipVariantForStatus(schedule.status)}>{schedule.status_label}</Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </Panel>
  );
}
