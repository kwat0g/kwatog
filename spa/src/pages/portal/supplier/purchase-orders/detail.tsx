import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { ArrowLeft, CheckCircle, Truck, FileDown, Upload, FileText, Send } from 'lucide-react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { useState } from 'react';

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

  const { data: po, isLoading } = useQuery({
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
    onError: (err: any) => {
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

  if (isLoading) return <SkeletonBlock className="h-96 rounded-lg" />;
  if (!po) return <EmptyState icon="file-x" title="Purchase order not found" />;

  const canAcknowledge = !po.sent_to_supplier_at;

  return (
    <div className="space-y-4 max-w-4xl">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Link to="/portal/supplier/purchase-orders" className="text-muted hover:text-primary p-1 -ml-1">
            <ArrowLeft size={16} />
          </Link>
          <div>
            <h2 className="text-sm font-semibold">{po.po_number}</h2>
            <p className="text-2xs text-muted">{po.date ?? '—'}</p>
          </div>
        </div>
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
            Update Shipment
          </Button>
          <Button variant="secondary" size="sm" icon={<Upload size={14} />} onClick={() => setShowUploadForm(!showUploadForm)}>
            Upload Doc
          </Button>
          <Button variant="secondary" size="sm" icon={<Send size={14} />} onClick={() => setShowInvoiceForm(!showInvoiceForm)}>
            Submit Invoice
          </Button>
        </div>
      </div>

      {/* Shipment form */}
      {showShipmentForm && (
        <Panel title="Update Shipment Information">
          <form onSubmit={(e) => { e.preventDefault(); shipmentMut.mutate(); }} className="flex flex-col gap-3">
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Tracking Number</label>
              <input type="text" value={trackingNumber} onChange={(e) => setTrackingNumber(e.target.value)}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Estimated Arrival</label>
              <input type="date" value={estimatedArrival} onChange={(e) => setEstimatedArrival(e.target.value)}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
            </div>
            <Button type="submit" variant="primary" size="sm" loading={shipmentMut.isPending}>
              Save
            </Button>
          </form>
        </Panel>
      )}

      {/* Upload Shipping Document form */}
      {showUploadForm && (
        <Panel title="Upload Shipping Document">
          <form onSubmit={(e) => { e.preventDefault(); if (uploadFile) uploadDocMut.mutate(); }} className="flex flex-col gap-3">
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Document Type</label>
              <select value={uploadDocType} onChange={(e) => setUploadDocType(e.target.value)}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent">
                <option value="commercial_invoice">Commercial Invoice</option>
                <option value="packing_list">Packing List</option>
                <option value="bill_of_lading">Bill of Lading</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">File (PDF, JPG, PNG — max 10MB)</label>
              <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                className="w-full text-xs" />
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Notes (optional)</label>
              <textarea value={uploadNotes} onChange={(e) => setUploadNotes(e.target.value)} rows={2}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
            </div>
            <Button type="submit" variant="primary" size="sm" disabled={!uploadFile} loading={uploadDocMut.isPending}>
              Upload
            </Button>
          </form>
        </Panel>
      )}

      {/* Submit Invoice form */}
      {showInvoiceForm && (
        <Panel title="Submit Invoice (Create Draft Bill)">
          <form onSubmit={(e) => { e.preventDefault(); submitInvoiceMut.mutate(); }} className="flex flex-col gap-3">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Your Invoice # *</label>
                <input type="text" value={billNumber} onChange={(e) => setBillNumber(e.target.value)} required
                  className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
              </div>
              <div>
                <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Invoice Date *</label>
                <input type="date" value={billDate} onChange={(e) => setBillDate(e.target.value)} required
                  className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
              </div>
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Due Date (optional)</label>
              <input type="date" value={billDueDate} onChange={(e) => setBillDueDate(e.target.value)}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Attach Invoice File (optional)</label>
              <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setInvoiceFile(e.target.files?.[0] ?? null)}
                className="w-full text-xs" />
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Remarks (optional)</label>
              <textarea value={billRemarks} onChange={(e) => setBillRemarks(e.target.value)} rows={2}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
            </div>
            <div className="text-2xs text-muted">
              Bill items will be auto-populated from the PO line items. A draft bill will be created in Accounts Payable for review.
            </div>
            <Button type="submit" variant="primary" size="sm" disabled={!billNumber || !billDate} loading={submitInvoiceMut.isPending}>
              <Send size={14} className="mr-1" /> Submit Invoice
            </Button>
          </form>
        </Panel>
      )}

      {/* Items */}
      <Panel title={`Items (${po.items?.length ?? 0})`}>
        {po.items && po.items.length > 0 ? (
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Part #</th>
                <th className="text-left py-2 px-3 font-medium">Description</th>
                <th className="text-right py-2 px-3 font-medium">Ordered</th>
                <th className="text-right py-2 px-3 font-medium">Received</th>
                <th className="text-right py-2 px-3 font-medium">Unit Price</th>
                <th className="text-right py-2 px-3 font-medium">Total</th>
              </tr>
            </thead>
            <tbody>
              {po.items.map((item) => (
                <tr key={item.id} className="border-b border-border/50">
                  <td className="py-2 px-3 font-mono text-muted">{item.part_number}</td>
                  <td className="py-2 px-3">{item.name}</td>
                  <td className="py-2 px-3 text-right">{item.quantity_ordered}</td>
                  <td className="py-2 px-3 text-right">{item.quantity_received}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(item.unit_price).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(item.total_price).toLocaleString()}</td>
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
        <Panel title={`Shipping Documents (${shippingDocs.length})`}>
          <div className="divide-y divide-border/50">
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
        <Panel title="Goods Receipt Notes">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">GRN #</th>
                <th className="text-left py-2 px-3 font-medium">Received Date</th>
              </tr>
            </thead>
            <tbody>
              {po.goods_receipt_notes.map((grn) => (
                <tr key={grn.id} className="border-b border-border/50">
                  <td className="py-2 px-3 font-mono">{grn.grn_number}</td>
                  <td className="py-2 px-3 text-muted">{grn.received_date ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {/* Bills */}
      {po.bills && po.bills.length > 0 && (
        <Panel title="Bills / Invoices">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Bill #</th>
                <th className="text-right py-2 px-3 font-medium">Amount</th>
                <th className="text-right py-2 px-3 font-medium">Paid</th>
                <th className="text-right py-2 px-3 font-medium">Balance</th>
                <th className="text-left py-2 px-3 font-medium">Due</th>
                <th className="text-right py-2 px-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {po.bills.map((bill) => (
                <tr key={bill.id} className="border-b border-border/50">
                  <td className="py-2 px-3 font-mono text-accent">{bill.bill_number}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(bill.total_amount).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(bill.paid_amount).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(bill.balance).toLocaleString()}</td>
                  <td className="py-2 px-3 text-muted">{bill.due_date ?? '—'}</td>
                  <td className="py-2 px-3 text-right">
                    <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium uppercase ${
                      bill.status === 'paid' ? 'bg-success/10 text-success' :
                      bill.status === 'overdue' ? 'bg-danger/10 text-danger' : 'bg-warning/10 text-warning'
                    }`}>{bill.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  );
}
