import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { CheckCircle, Truck, FileDown, Upload, FileText, Send } from 'lucide-react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { FileInput } from '@/components/ui/FileInput';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { useState } from 'react';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
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

export default function SupplierPurchaseOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [showShipmentForm, setShowShipmentForm] = useState(false);
  const [showUploadForm, setShowUploadForm] = useState(false);
  const [showInvoiceForm, setShowInvoiceForm] = useState(false);
  const [trackingNumber, setTrackingNumber] = useState('');
  const [estimatedArrival, setEstimatedArrival] = useState('');

  // Shipping doc upload state
  const [uploadDocType, setUploadDocType] = useState('commercial_invoice');
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadNotes, setUploadNotes] = useState('');

  // Invoice submission state
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
    mutationFn: () => supplierPortalApi.updateShipment(id!, { tracking_number: trackingNumber, estimated_arrival: estimatedArrival || undefined }),
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

  const canAcknowledge = !!po && !po.sent_to_supplier_at;

  return (
    <div>
      <PageHeader
        title={po?.po_number ?? 'Purchase order'}
        subtitle={po?.date ?? undefined}
        backTo="/portal/supplier/purchase-orders"
        backLabel="Purchase orders"
        actions={po ? (
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="sm" icon={<FileDown size={14} />} onClick={downloadPdf}>
              PDF
            </Button>
            {canAcknowledge && (
              <Button variant="primary" size="sm" icon={<CheckCircle size={14} />} onClick={() => acknowledgeMut.mutate()} loading={acknowledgeMut.isPending}>
                Acknowledge PO
              </Button>
            )}
            <Button variant="secondary" size="sm" icon={<Truck size={14} />} onClick={() => setShowShipmentForm(!showShipmentForm)}>
              Update shipment
            </Button>
            <Button variant="secondary" size="sm" icon={<Upload size={14} />} onClick={() => setShowUploadForm(!showUploadForm)}>
              Upload doc
            </Button>
            <Button variant="secondary" size="sm" icon={<Send size={14} />} onClick={() => setShowInvoiceForm(!showInvoiceForm)}>
              Submit invoice
            </Button>
          </div>
        ) : undefined}
      />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 space-y-4 max-w-4xl">
        {isLoading && <SkeletonBlock className="h-96 rounded-md" />}

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
            {/* Shipment form */}
            {showShipmentForm && (
              <Panel title="Update shipment information">
                <form onSubmit={(e) => { e.preventDefault(); shipmentMut.mutate(); }} className="flex flex-col gap-3">
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
                  <Button type="submit" variant="primary" size="sm" loading={shipmentMut.isPending} className="self-start">
                    Save
                  </Button>
                </form>
              </Panel>
            )}

            {/* Upload Shipping Document form */}
            {showUploadForm && (
              <Panel title="Upload shipping document">
                <form onSubmit={(e) => { e.preventDefault(); if (uploadFile) uploadDocMut.mutate(); }} className="flex flex-col gap-3">
                  <Select label="Document type" value={uploadDocType} onChange={(e) => setUploadDocType(e.target.value)}>
                    <option value="commercial_invoice">Commercial invoice</option>
                    <option value="packing_list">Packing list</option>
                    <option value="bill_of_lading">Bill of lading</option>
                    <option value="other">Other</option>
                  </Select>
                  <FileInput
                    label="File"
                    helper="PDF, JPG, or PNG — max 10MB"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                  />
                  <Textarea
                    label="Notes (optional)"
                    value={uploadNotes}
                    onChange={(e) => setUploadNotes(e.target.value)}
                    rows={2}
                  />
                  <Button type="submit" variant="primary" size="sm" disabled={!uploadFile} loading={uploadDocMut.isPending} className="self-start">
                    Upload
                  </Button>
                </form>
              </Panel>
            )}

            {/* Submit Invoice form */}
            {showInvoiceForm && (
              <Panel title="Submit invoice (creates a draft bill)">
                <form onSubmit={(e) => { e.preventDefault(); submitInvoiceMut.mutate(); }} className="flex flex-col gap-3">
                  <div className="grid grid-cols-2 gap-3">
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
                  </div>
                  <Input
                    label="Due date (optional)"
                    type="date"
                    value={billDueDate}
                    onChange={(e) => setBillDueDate(e.target.value)}
                  />
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
                  <div className="text-2xs text-muted">
                    Bill items will be auto-populated from the PO line items. A draft bill will be created in Accounts Payable for review.
                  </div>
                  <Button
                    type="submit"
                    variant="primary"
                    size="sm"
                    icon={<Send size={14} />}
                    disabled={!billNumber || !billDate}
                    loading={submitInvoiceMut.isPending}
                    className="self-start"
                  >
                    Submit invoice
                  </Button>
                </form>
              </Panel>
            )}

            {/* Items */}
            <Panel title={`Items (${po.items?.length ?? 0})`} noPadding>
              {po.items && po.items.length > 0 ? (
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Part #</Th>
                      <Th>Description</Th>
                      <Th align="right">Ordered</Th>
                      <Th align="right">Received</Th>
                      <Th align="right">Unit price</Th>
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
              ) : (
                <EmptyState icon="package" title="No items" />
              )}
            </Panel>

            {/* Shipping Documents */}
            {shippingDocs && shippingDocs.length > 0 && (
              <Panel title={`Shipping Documents (${shippingDocs.length})`} noPadding>
                <div className="divide-y divide-default/50">
                  {shippingDocs.map((doc) => (
                    <div key={doc.id} className="flex items-center justify-between py-2 px-3">
                      <div className="flex items-center gap-3 min-w-0">
                        <FileText size={14} className="text-muted shrink-0" />
                        <div className="min-w-0">
                          <p className="text-xs font-medium truncate">{doc.original_filename}</p>
                          <p className="text-2xs text-muted">{doc.document_type_label} · {doc.file_size_formatted}</p>
                        </div>
                      </div>
                      <a href={doc.download_url} target="_blank" rel="noopener noreferrer"
                        className="text-xs text-accent hover:underline shrink-0 ml-3">
                        Download
                      </a>
                    </div>
                  ))}
                </div>
              </Panel>
            )}

            {/* GRNs */}
            {po.goods_receipt_notes && po.goods_receipt_notes.length > 0 && (
              <Panel title="Goods Receipt Notes" noPadding>
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
                        <Td className="text-muted">{grn.received_date ?? '—'}</Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Panel>
            )}

            {/* Bills */}
            {po.bills && po.bills.length > 0 && (
              <Panel title="Bills / Invoices" noPadding>
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Bill #</Th>
                      <Th align="right">Amount</Th>
                      <Th align="right">Paid</Th>
                      <Th align="right">Balance</Th>
                      <Th>Due</Th>
                      <Th align="right">Status</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {po.bills.map((bill) => (
                      <tr key={bill.id} className={trCls}>
                        <Td mono className="text-accent">{bill.bill_number}</Td>
                        <Td align="right" mono>{formatPeso(bill.total_amount)}</Td>
                        <Td align="right" mono>{formatPeso(bill.paid_amount)}</Td>
                        <Td align="right" mono>{formatPeso(bill.balance)}</Td>
                        <Td className="text-muted">{bill.due_date ?? '—'}</Td>
                        <Td align="right" mono>
                          <Chip variant={chipVariantForStatus(bill.status)}>{bill.status}</Chip>
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Panel>
            )}
          </>
        )}
      </div>
    </div>
  );
}
