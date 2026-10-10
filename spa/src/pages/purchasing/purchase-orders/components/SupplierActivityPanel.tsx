import { useQuery } from '@tanstack/react-query';
import { supplierActivityApi } from '@/api/b2b/supplier-activity';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatDate } from '@/lib/formatDate';
import { overDispatchedLines } from '@/lib/supplierDispatch';
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
            <Th>Applies to</Th>
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
              <Td>{doc.supplier_shipment_id ? 'Shipment' : 'PO-wide'}</Td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Shipments and documents the supplier reported against this PO. */
export function SupplierActivityPanel({ purchaseOrderId, orderedLines }: {
  purchaseOrderId: string;
  orderedLines: Array<{ id: string; quantity: string; description: string }>;
}) {
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

  const nothingReported = data.shipments.length === 0 && data.documents.length === 0;
  const overDispatched = overDispatchedLines(data.shipments, orderedLines);

  return (
    <Panel title="Supplier activity" meta="Reported through the supplier portal" noPadding>
      {nothingReported ? (
        <p className="px-3 py-2 text-sm text-muted">
          The supplier has not reported shipments or documents yet.
        </p>
      ) : (
        <div className="divide-y divide-default">
          {data.shipments.length > 0 && (
            <div className="overflow-x-auto">
              {overDispatched.length > 0 && <p role="alert" className="px-3 py-2 text-sm text-warning-fg">
                Recorded dispatch exceeds ordered quantity for {overDispatched.map((line) => orderedLines.find((item) => item.id === line.id)?.description ?? 'PO item').join(', ')}. Review shipment history before receiving.
              </p>}
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Carrier</Th>
                    <Th>Tracking</Th>
                    <Th>Shipped</Th>
                    <Th>ETA</Th>
                    <Th>Dispatched</Th>
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
                      <Td mono>{shipment.estimated_arrival ? formatDate(shipment.estimated_arrival) : '—'}
                        {shipment.late_eta && <div className="text-warning-fg text-xs">At risk: ETA after confirmed date</div>}
                      </Td>
                      <Td>{shipment.quantity_recorded ? shipment.items?.map((line) => <div key={line.purchase_order_item_id}>
                        {line.description ?? 'PO item'}: {line.quantity_shipped}
                      </div>) : 'Quantity not recorded'}
                        {shipment.notes && <div className="text-xs text-muted">Reason / notes: {shipment.notes}</div>}
                      </Td>
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

        </div>
      )}
    </Panel>
  );
}
