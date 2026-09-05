import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { useState } from 'react';
import { LuCircleCheck, LuTruck, LuFileDown, LuUpload, LuFileText, LuSend } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { PortalShippingDocument } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { FileInput } from '@/components/ui/FileInput';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatCard } from '@/components/ui/StatCard';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

function downloadBlob(blob: Blob, filename: string) {
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  window.URL.revokeObjectURL(url);
  a.remove();
}

async function downloadShippingDoc(doc: PortalShippingDocument) {
  try {
    const blob = await supplierPortalApi.downloadShippingDocument(doc.id);
    downloadBlob(blob, doc.original_filename);
  } catch {
    toast.error('Failed to download the shipping document.');
  }
}

export default function SupplierPurchaseOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [showShipmentForm, setShowShipmentForm] = useState(false);
  const [showUploadForm, setShowUploadForm] = useState(false);
  const [showInvoiceForm, setShowInvoiceForm] = useState(false);
  const [trackingNumber, setTrackingNumber] = useState('');
  const [shippedDate, setShippedDate] = useState('');
  const [carrier, setCarrier] = useState('');
  const [estimatedArrival, setEstimatedArrival] = useState('');
  const [shipmentNotes, setShipmentNotes] = useState('');

  const [uploadDocType, setUploadDocType] = useState('');
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadNotes, setUploadNotes] = useState('');
  const { data: shippingOptions } = useQuery({
    queryKey: ['portal', 'supplier', 'shipping-document-options'],
    queryFn: () => supplierPortalApi.shippingDocumentOptions(),
  });

  const [billNumber, setBillNumber] = useState('');
  const [billDate, setBillDate] = useState('');
  const [billDueDate, setBillDueDate] = useState('');
  const [invoiceFile, setInvoiceFile] = useState<File | null>(null);
  const [billRemarks, setBillRemarks] = useState('');

  const { data: po, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'po', id],
    queryFn: () => supplierPortalApi.getPo(id!),
    enabled: !!id,
  });

  const { data: shippingDocs, refetch: refetchDocs } = useQuery({
    queryKey: ['portal', 'supplier', 'po', id, 'shipping-documents'],
    queryFn: () => supplierPortalApi.listShippingDocuments(id!),
    enabled: !!id,
  });

  const acknowledgeMut = useMutation({
    mutationFn: () => supplierPortalApi.acknowledgePo(id!),
    onSuccess: () => {
      toast.success('Purchase order acknowledged.');
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'po', id] });
    },
    onError: () => toast.error('Failed to acknowledge PO.'),
  });

  const shipmentMut = useMutation({
    mutationFn: () => supplierPortalApi.updateShipment(id!, {
      shipped_date: shippedDate || undefined,
      carrier: carrier.trim() || undefined,
      tracking_number: trackingNumber.trim() || undefined,
      estimated_arrival: estimatedArrival || undefined,
      notes: shipmentNotes.trim() || undefined,
    }),
    onSuccess: () => {
      toast.success('Shipment details updated.');
      setShowShipmentForm(false);
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'po', id] });
    },
    onError: () => toast.error('Failed to update shipment.'),
  });

  const uploadDocMut = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.append('document_type', uploadDocType);
      form.append('file', uploadFile!);
      if (uploadNotes) form.append('notes', uploadNotes);
      return supplierPortalApi.uploadShippingDocument(id!, form);
    },
    onSuccess: () => {
      toast.success('Document uploaded.');
      setShowUploadForm(false);
      setUploadFile(null);
      setUploadNotes('');
      refetchDocs();
    },
    onError: () => toast.error('Failed to upload document.'),
  });

  const submitInvoiceMut = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.append('bill_number', billNumber);
      form.append('date', billDate);
      if (billDueDate) form.append('due_date', billDueDate);
      if (invoiceFile) form.append('file', invoiceFile);
      if (billRemarks) form.append('remarks', billRemarks);
      return supplierPortalApi.submitInvoice(id!, form);
    },
    onSuccess: (res) => {
      toast.success(res.message ?? 'Invoice submitted.');
      setShowInvoiceForm(false);
      setBillNumber('');
      setBillDate('');
      setBillDueDate('');
      setInvoiceFile(null);
      setBillRemarks('');
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'po', id] });
    },
    onError: (err: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(err?.response?.data?.message ?? 'Failed to submit invoice.');
    },
  });

  const downloadPdf = async () => {
    try {
      const blob = await supplierPortalApi.downloadPoPdf(id!);
      downloadBlob(blob, `${po?.po_number ?? 'PO'}.pdf`);
    } catch {
      toast.error('Failed to download PDF.');
    }
  };

  // The API owns the lifecycle policy and publishes capabilities with the PO.
  // Keep the client as a renderer of that contract, not a second state machine.
  const canAcknowledge = po?.capabilities.can_acknowledge ?? false;
  const canUpdateShipment = po?.capabilities.can_update_shipment ?? false;
  const canUploadDocument = po?.capabilities.can_upload_document ?? false;
  const canSubmitInvoice = po?.capabilities.can_submit_invoice ?? false;

  const openShipmentForm = () => {
    setShippedDate(po?.shipment?.shipped_date ?? '');
    setCarrier(po?.shipment?.carrier ?? '');
    setTrackingNumber(po?.shipment?.tracking_number ?? '');
    setEstimatedArrival(po?.shipment?.estimated_arrival ?? '');
    setShipmentNotes(po?.shipment?.notes ?? '');
    setShowShipmentForm((open) => !open);
  };

  return (
    <div>
      <PageHeader
        title={
          po ? (
            <>
              {po.po_number}{' '}
              <Chip variant={chipVariantForStatus(po.status)}>
                {po.status_label ?? po.status.replace(/_/g, ' ')}
              </Chip>
            </>
          ) : (
            'Purchase order'
          )
        }
        subtitle={po?.date ? formatDate(po.date) : undefined}
        backTo="/portal/supplier/purchase-orders"
        backLabel="Purchase orders"
        actions={po ? (
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="sm" icon={<LuFileDown size={14} />} onClick={downloadPdf}>
              PDF
            </Button>
            {canAcknowledge && (
              <Button variant="primary" size="sm" icon={<LuCircleCheck size={14} />} onClick={() => acknowledgeMut.mutate()} loading={acknowledgeMut.isPending}>
                Acknowledge PO
              </Button>
            )}
            {canUpdateShipment && (
              <Button variant="secondary" size="sm" icon={<LuTruck size={14} />} onClick={openShipmentForm}>
                Update shipment
              </Button>
            )}
            {canUploadDocument && (
              <Button variant="secondary" size="sm" icon={<LuUpload size={14} />} onClick={() => setShowUploadForm(!showUploadForm)}>
                Upload doc
              </Button>
            )}
            {canSubmitInvoice && (
              <Button variant="secondary" size="sm" icon={<LuSend size={14} />} onClick={() => setShowInvoiceForm(!showInvoiceForm)}>
                Submit invoice
              </Button>
            )}
          </div>
        ) : undefined}
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load purchase order"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && !po && (
          <EmptyState icon="file-x" title="Purchase order not found" />
        )}

        {!isLoading && !isError && po && (
          <>
            <KpiGrid count={4}>
              <StatCard label="Total Amount" value={formatPeso(po.total_amount)} />
              <StatCard
                label="Expected Delivery"
                value={po.expected_delivery_date ? formatDate(po.expected_delivery_date) : '—'}
              />
              <StatCard label="Incoterm" value={po.incoterm ?? '—'} />
              <StatCard label="Receipts" value={po.goods_receipt_notes.length} helper="Goods receipts posted" />
            </KpiGrid>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <Panel title="Shipment">
                {po.shipment ? (
                  <dl className="grid grid-cols-2 gap-y-3 gap-x-6 text-sm">
                    <div>
                      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Shipped date</dt>
                      <dd className="font-mono">{po.shipment.shipped_date ? formatDate(po.shipment.shipped_date) : '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Carrier</dt>
                      <dd>{po.shipment.carrier ?? '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Tracking number</dt>
                      <dd className="font-mono">{po.shipment.tracking_number ?? '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Estimated arrival</dt>
                      <dd className="font-mono">{po.shipment.estimated_arrival ? formatDate(po.shipment.estimated_arrival) : '—'}</dd>
                    </div>
                    {po.shipment.notes && (
                      <div className="col-span-2">
                        <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Notes</dt>
                        <dd>{po.shipment.notes}</dd>
                      </div>
                    )}
                  </dl>
                ) : (
                  <p className="text-sm text-muted">No shipment details submitted yet.</p>
                )}
              </Panel>

              {shippingDocs && shippingDocs.length > 0 && (
                <Panel title="Shipping Documents" meta={String(shippingDocs.length)}>
                  <div className="divide-y divide-subtle">
                    {shippingDocs.map((doc) => (
                      <div key={doc.id} className="flex items-center justify-between py-2 first:pt-0 last:pb-0">
                        <div className="flex items-center gap-2.5 min-w-0">
                          <LuFileText size={14} className="text-muted shrink-0" />
                          <div className="min-w-0">
                            <p className="text-xs font-medium truncate">{doc.original_filename}</p>
                            <p className="text-2xs text-muted">{doc.document_type_label} · {doc.file_size_formatted}</p>
                          </div>
                        </div>
                        <Button variant="ghost" size="sm" onClick={() => void downloadShippingDoc(doc)}>
                          Download
                        </Button>
                      </div>
                    ))}
                  </div>
                </Panel>
              )}
            </div>

            {showShipmentForm && canUpdateShipment && (
              <Panel title="Update shipment information">
                <form onSubmit={(e) => { e.preventDefault(); shipmentMut.mutate(); }} className="flex flex-col gap-3">
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <Input
                      label="Shipped date"
                      type="date"
                      value={shippedDate}
                      onChange={(e) => setShippedDate(e.target.value)}
                    />
                    <Input
                      label="Carrier"
                      type="text"
                      value={carrier}
                      onChange={(e) => setCarrier(e.target.value)}
                      maxLength={100}
                    />
                    <Input
                      label="Tracking number"
                      type="text"
                      value={trackingNumber}
                      onChange={(e) => setTrackingNumber(e.target.value)}
                    />
                    <Input
                      label="Estimated arrival"
                      type="date"
                      value={estimatedArrival}
                      onChange={(e) => setEstimatedArrival(e.target.value)}
                    />
                  </div>
                  <Textarea
                    label="Notes (optional)"
                    value={shipmentNotes}
                    onChange={(e) => setShipmentNotes(e.target.value)}
                    rows={2}
                    maxLength={500}
                  />
                  <div className="flex justify-end gap-2 pt-2 border-t border-default">
                    <Button type="button" variant="secondary" size="sm" onClick={() => setShowShipmentForm(false)}>
                      Cancel
                    </Button>
                    <Button type="submit" variant="primary" size="sm" loading={shipmentMut.isPending}>
                      Save shipment
                    </Button>
                  </div>
                </form>
              </Panel>
            )}

            {showUploadForm && canUploadDocument && (
              <Panel title="Upload shipping document">
                <form onSubmit={(e) => { e.preventDefault(); if (uploadFile) uploadDocMut.mutate(); }} className="flex flex-col gap-3">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <Select label="Document type" value={uploadDocType} onChange={(e) => setUploadDocType(e.target.value)}>
                      <option value="">— Select —</option>
                      {(shippingOptions?.document_types ?? []).map((type) => (
                        <option key={type.value} value={type.value}>{type.label}</option>
                      ))}
                    </Select>
                    <FileInput
                      label="File"
                      helper="PDF, JPG, or PNG — max 10MB"
                      accept=".pdf,.jpg,.jpeg,.png"
                      onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                    />
                  </div>
                  <Textarea
                    label="Notes (optional)"
                    value={uploadNotes}
                    onChange={(e) => setUploadNotes(e.target.value)}
                    rows={2}
                  />
                  <div className="flex justify-end gap-2 pt-2 border-t border-default">
                    <Button type="button" variant="secondary" size="sm" onClick={() => setShowUploadForm(false)}>
                      Cancel
                    </Button>
                    <Button type="submit" variant="primary" size="sm" disabled={!uploadFile || !uploadDocType} loading={uploadDocMut.isPending}>
                      Upload
                    </Button>
                  </div>
                </form>
              </Panel>
            )}

            {showInvoiceForm && canSubmitInvoice && (
              <Panel title="Submit invoice" meta="Creates a draft bill for AP review">
                <form onSubmit={(e) => { e.preventDefault(); submitInvoiceMut.mutate(); }} className="flex flex-col gap-3">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <Input
                      label="Your invoice #"
                      required
                      type="text"
                      value={billNumber}
                      onChange={(e) => setBillNumber(e.target.value)}
                      className="font-mono"
                    />
                    <Input
                      label="Invoice date"
                      required
                      type="date"
                      value={billDate}
                      onChange={(e) => setBillDate(e.target.value)}
                    />
                    <Input
                      label="Due date (optional)"
                      type="date"
                      value={billDueDate}
                      onChange={(e) => setBillDueDate(e.target.value)}
                    />
                  </div>
                  <FileInput
                    label="Attach invoice file (optional)"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(e) => setInvoiceFile(e.target.files?.[0] ?? null)}
                  />
                  <Textarea
                    label="Remarks (optional)"
                    value={billRemarks}
                    onChange={(e) => setBillRemarks(e.target.value)}
                    rows={2}
                  />
                  <p className="text-2xs text-muted">
                    Bill items will be auto-populated from the PO line items. A draft bill will be created in Accounts Payable for review.
                  </p>
                  <div className="flex justify-end gap-2 pt-2 border-t border-default">
                    <Button type="button" variant="secondary" size="sm" onClick={() => setShowInvoiceForm(false)}>
                      Cancel
                    </Button>
                    <Button
                      type="submit"
                      variant="primary"
                      size="sm"
                      icon={<LuSend size={14} />}
                      disabled={!billNumber || !billDate}
                      loading={submitInvoiceMut.isPending}
                    >
                      Submit invoice
                    </Button>
                  </div>
                </form>
              </Panel>
            )}

            <Panel title="Line items" meta={String(po.items?.length ?? 0)} noPadding>
              {po.items && po.items.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Part #</Th>
                        <Th>Description</Th>
                        <Th align="right">Ordered</Th>
                        <Th align="right">Received</Th>
                        <Th align="right">Unit Price</Th>
                        <Th align="right">Total</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {po.items.map((item) => (
                        <tr key={item.id} className={trCls}>
                          <Td mono className="text-muted">{item.part_number}</Td>
                          <Td>{item.name}</Td>
                          <Td align="right" mono>{item.quantity_ordered}</Td>
                          <Td align="right" mono>{item.quantity_received}</Td>
                          <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
                          <Td align="right" mono>{formatPeso(item.total_price)}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState icon="package" title="No items" />
              )}
            </Panel>

            {po.goods_receipt_notes.length > 0 && (
              <Panel title="Goods Receipt Notes" meta={String(po.goods_receipt_notes.length)} noPadding>
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>GRN #</Th>
                        <Th>Received Date</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {po.goods_receipt_notes.map((grn) => (
                        <tr key={grn.id} className={trCls}>
                          <Td mono>{grn.grn_number}</Td>
                          <Td className="text-muted">{grn.received_date ? formatDate(grn.received_date) : '—'}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            )}

            {po.bills.length > 0 && (
              <Panel title="Bills / Invoices" meta={String(po.bills.length)} noPadding>
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Bill #</Th>
                        <Th align="right">Amount</Th>
                        <Th align="right">Paid</Th>
                        <Th align="right">Balance</Th>
                        <Th>Due</Th>
                        <Th>Status</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {po.bills.map((bill) => (
                        <tr key={bill.id} className={trCls}>
                          <Td mono className="text-accent">{bill.bill_number}</Td>
                          <Td align="right" mono>{formatPeso(bill.total_amount)}</Td>
                          <Td align="right" mono>{formatPeso(bill.paid_amount)}</Td>
                          <Td align="right" mono>{formatPeso(bill.balance)}</Td>
                          <Td className="text-muted">{bill.due_date ? formatDate(bill.due_date) : '—'}</Td>
                          <Td>
                            <Chip variant={chipVariantForStatus(bill.status)}>{bill.status_label ?? bill.status}</Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            )}
          </>
        )}
      </div>
    </div>
  );
}
