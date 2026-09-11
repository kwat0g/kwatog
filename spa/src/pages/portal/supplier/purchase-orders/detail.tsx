import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { useState } from 'react';
import { LuCircleCheck, LuTruck, LuFileDown, LuUpload, LuFileText, LuSend, LuPencil, LuThumbsDown } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { PortalShippingDocument, RespondToPurchaseOrderPayload } from '@/types/b2b';
import type { PurchaseOrderResponseStatus, PurchaseOrderResponseType } from '@/types/purchasing';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { FileInput } from '@/components/ui/FileInput';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
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

const DECIMAL_RE = /^\d+(\.\d{1,2})?$/;

const responseTypeLabel: Record<PurchaseOrderResponseType, string> = {
 accept: 'Accepted',
 propose: 'Changes proposed',
 decline: 'Declined',
};
const responseTypeVariant: Record<PurchaseOrderResponseType, 'success' | 'warning' | 'danger'> = {
 accept: 'success',
 propose: 'warning',
 decline: 'danger',
};
const responseStatusVariant: Record<PurchaseOrderResponseStatus, 'neutral' | 'warning' | 'success' | 'danger'> = {
 pending: 'warning',
 accepted: 'success',
 rejected: 'danger',
 superseded: 'neutral',
};

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

  const [acceptOpen, setAcceptOpen] = useState(false);
  const [acceptDate, setAcceptDate] = useState('');
  const [acceptNotes, setAcceptNotes] = useState('');
  const [declineOpen, setDeclineOpen] = useState(false);
  const [proposeOpen, setProposeOpen] = useState(false);
  const [proposeDeliveryDate, setProposeDeliveryDate] = useState('');
  const [proposeNotes, setProposeNotes] = useState('');
  const [proposedLines, setProposedLines] = useState<Record<string, { quantity: string; unit_price: string; reason: string }>>({});
  const [lineErrors, setLineErrors] = useState<Record<string, string>>({});
  const [proposeError, setProposeError] = useState<string | null>(null);

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

  const respondMut = useMutation({
    mutationFn: (payload: RespondToPurchaseOrderPayload) => supplierPortalApi.respondToPurchaseOrder(id!, payload),
    onSuccess: (_po, payload) => {
      toast.success(
        payload.type === 'accept'
          ? 'Purchase order accepted.'
          : payload.type === 'propose'
          ? 'Counter-proposal sent to OGAMI.'
          : 'Purchase order declined.',
      );
      setAcceptOpen(false);
      setProposeOpen(false);
      setDeclineOpen(false);
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'po', id] });
    },
    onError: (err: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(err?.response?.data?.message ?? 'Failed to send your response.');
    },
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
  const canRespond = po?.capabilities.can_respond ?? false;
  const canUpdateShipment = po?.capabilities.can_update_shipment ?? false;
  const canUploadDocument = po?.capabilities.can_upload_document ?? false;
  const canSubmitInvoice = po?.capabilities.can_submit_invoice ?? false;

  const openAccept = () => {
    setAcceptDate(po?.expected_delivery_date ?? '');
    setAcceptNotes('');
    setAcceptOpen(true);
  };

  const openPropose = () => {
    const next: Record<string, { quantity: string; unit_price: string; reason: string }> = {};
    for (const item of po?.items ?? []) {
      next[item.id] = { quantity: item.quantity_ordered, unit_price: item.unit_price, reason: '' };
    }
    setProposedLines(next);
    setProposeDeliveryDate(po?.expected_delivery_date ?? '');
    setProposeNotes('');
    setLineErrors({});
    setProposeError(null);
    setProposeOpen(true);
  };

  const submitPropose = () => {
    const items: NonNullable<RespondToPurchaseOrderPayload['items']> = [];
    const errors: Record<string, string> = {};
    for (const item of po?.items ?? []) {
      const draft = proposedLines[item.id];
      if (!draft) continue;
      const qtyChanged = draft.quantity !== item.quantity_ordered;
      const priceChanged = draft.unit_price !== item.unit_price;
      if (!qtyChanged && !priceChanged) continue;
      if (qtyChanged && (!DECIMAL_RE.test(draft.quantity) || Number(draft.quantity) <= 0)) {
        errors[`${item.id}:quantity`] = 'Enter a positive quantity (up to 2 decimals).';
      }
      if (priceChanged && (!DECIMAL_RE.test(draft.unit_price) || Number(draft.unit_price) <= 0)) {
        errors[`${item.id}:unit_price`] = 'Enter a positive price (up to 2 decimals).';
      }
      if (!draft.reason.trim()) errors[`${item.id}:reason`] = 'Explain why this line changed.';
      items.push({
        purchase_order_item_id: item.id,
        ...(qtyChanged ? { proposed_quantity: draft.quantity } : {}),
        ...(priceChanged ? { proposed_unit_price: draft.unit_price } : {}),
        reason: draft.reason.trim(),
      });
    }
    setLineErrors(errors);
    if (Object.keys(errors).length > 0) return;
    if (items.length === 0) {
      setProposeError('Change at least one line before sending a proposal.');
      return;
    }
    setProposeError(null);
    respondMut.mutate({
      type: 'propose',
      proposed_delivery_date: proposeDeliveryDate || undefined,
      notes: proposeNotes.trim() || undefined,
      items,
    });
  };

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
            {canRespond && (
              <>
                <Button variant="primary" size="sm" icon={<LuCircleCheck size={14} />} onClick={openAccept} disabled={respondMut.isPending} loading={respondMut.isPending && acceptOpen}>
                  Accept
                </Button>
                <Button variant="secondary" size="sm" icon={<LuPencil size={14} />} onClick={openPropose} disabled={respondMut.isPending}>
                  Propose changes
                </Button>
                <Button variant="secondary" size="sm" icon={<LuThumbsDown size={14} />} onClick={() => setDeclineOpen(true)} disabled={respondMut.isPending}>
                  Decline
                </Button>
              </>
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
            <KpiGrid count={5}>
              <StatCard label="Total Amount" value={formatPeso(po.total_amount)} />
              <StatCard
                label="Required Delivery"
                value={po.expected_delivery_date ? formatDate(po.expected_delivery_date) : '—'}
              />
              <StatCard
                label="Confirmed Delivery"
                value={po.confirmed_delivery_date ? formatDate(po.confirmed_delivery_date) : '—'}
                helper={po.confirmed_delivery_date ? undefined : 'Not yet confirmed'}
              />
              <StatCard label="Incoterm" value={po.incoterm ?? '—'} />
              <StatCard label="Receipts" value={po.goods_receipt_notes.length} helper="Goods receipts posted" />
            </KpiGrid>

            {po.latest_response && (
              <Panel
                title="Your latest response"
                meta={
                  <Chip variant={responseTypeVariant[po.latest_response.type]}>
                    {responseTypeLabel[po.latest_response.type]}
                  </Chip>
                }
              >
                <div className="space-y-3 text-sm">
                  <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">Status</div>
                      <Chip variant={responseStatusVariant[po.latest_response.status]}>
                        {po.latest_response.status.replace(/_/g, ' ')}
                      </Chip>
                    </div>
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">Proposed delivery</div>
                      <div className="font-mono">
                        {po.latest_response.proposed_delivery_date
                          ? formatDate(po.latest_response.proposed_delivery_date)
                          : '—'}
                      </div>
                    </div>
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">Responded</div>
                      <div className="font-mono">
                        {po.latest_response.responded_at ? formatDate(po.latest_response.responded_at) : '—'}
                      </div>
                    </div>
                  </div>
                  {po.latest_response.notes && (
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">Notes</div>
                      <p className="text-secondary">{po.latest_response.notes}</p>
                    </div>
                  )}
                  {po.latest_response.resolution_notes && (
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">OGAMI response</div>
                      <p className="text-secondary">{po.latest_response.resolution_notes}</p>
                    </div>
                  )}
                  {po.latest_response.items.length > 0 && (
                    <div className="overflow-x-auto">
                      <table className={tableCls}>
                        <thead>
                          <tr className={theadTrCls}>
                            <Th>Item</Th>
                            <Th align="right">Proposed qty</Th>
                            <Th align="right">Proposed price</Th>
                            <Th>Reason</Th>
                          </tr>
                        </thead>
                        <tbody>
                          {po.latest_response.items.map((line) => {
                            const item = po.items.find((i) => i.id === line.purchase_order_item_id);
                            return (
                              <tr key={`${line.purchase_order_item_id}-${line.proposed_quantity ?? ''}-${line.proposed_unit_price ?? ''}`} className={trCls}>
                                <Td>
                                  <span className="font-mono text-muted">{item?.part_number ?? '—'}</span>
                                  {item ? ` · ${item.name}` : ''}
                                </Td>
                                <Td align="right" mono>{line.proposed_quantity ?? '—'}</Td>
                                <Td align="right" mono>{line.proposed_unit_price ? formatPeso(line.proposed_unit_price) : '—'}</Td>
                                <Td className="text-secondary">{line.reason ?? '—'}</Td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              </Panel>
            )}

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

      <Modal
        isOpen={acceptOpen}
        onClose={() => (respondMut.isPending ? undefined : setAcceptOpen(false))}
        title="Accept purchase order"
        size="md"
      >
        <div className="space-y-4 py-2">
          <p className="text-sm text-secondary">
            Accepting confirms the order as written. You may optionally confirm a delivery date and add notes.
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Confirmed delivery date (optional)"
              type="date"
              value={acceptDate}
              onChange={(e) => setAcceptDate(e.target.value)}
            />
          </div>
          <Textarea
            label="Notes (optional)"
            value={acceptNotes}
            onChange={(e) => setAcceptNotes(e.target.value)}
            rows={3}
            maxLength={500}
          />
        </div>
        <ModalFooter>
          <Button variant="secondary" size="sm" onClick={() => setAcceptOpen(false)} disabled={respondMut.isPending}>
            Cancel
          </Button>
          <Button
            variant="primary"
            size="sm"
            icon={<LuCircleCheck size={14} />}
            loading={respondMut.isPending}
            disabled={respondMut.isPending}
            onClick={() =>
              respondMut.mutate({
                type: 'accept',
                proposed_delivery_date: acceptDate || undefined,
                notes: acceptNotes.trim() || undefined,
              })
            }
          >
            Accept PO
          </Button>
        </ModalFooter>
      </Modal>

      <Modal
        isOpen={proposeOpen}
        onClose={() => (respondMut.isPending ? undefined : setProposeOpen(false))}
        title="Propose changes"
        size="xl"
      >
        <div className="space-y-4 py-2">
          <p className="text-sm text-secondary">
            Edit the lines you want to change. Only changed lines are sent. A reason is required for each.
          </p>
          <div className="overflow-x-auto">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Item</Th>
                  <Th align="right">Ordered qty</Th>
                  <Th align="right">Proposed qty</Th>
                  <Th align="right">Unit price</Th>
                  <Th align="right">Proposed price</Th>
                  <Th>Reason for change</Th>
                </tr>
              </thead>
              <tbody>
                {(po?.items ?? []).map((item) => {
                  const draft = proposedLines[item.id] ?? { quantity: item.quantity_ordered, unit_price: item.unit_price, reason: '' };
                  return (
                    <tr key={item.id} className={trCls}>
                      <Td>
                        <span className="font-mono text-muted">{item.part_number}</span>
                        <div className="text-2xs text-muted">{item.name}</div>
                      </Td>
                      <Td align="right" mono>{item.quantity_ordered}</Td>
                      <Td align="right">
                        <Input
                          type="text"
                          inputMode="decimal"
                          value={draft.quantity}
                          onChange={(e) => setProposedLines((cur) => ({ ...cur, [item.id]: { ...draft, quantity: e.target.value } }))}
                          error={lineErrors[`${item.id}:quantity`]}
                          fieldSize="sm"
                          className="text-right font-mono w-24"
                          aria-label={`Proposed quantity for ${item.part_number}`}
                        />
                      </Td>
                      <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
                      <Td align="right">
                        <Input
                          type="text"
                          inputMode="decimal"
                          value={draft.unit_price}
                          onChange={(e) => setProposedLines((cur) => ({ ...cur, [item.id]: { ...draft, unit_price: e.target.value } }))}
                          error={lineErrors[`${item.id}:unit_price`]}
                          fieldSize="sm"
                          className="text-right font-mono w-28"
                          aria-label={`Proposed price for ${item.part_number}`}
                        />
                      </Td>
                      <Td>
                        <Input
                          type="text"
                          value={draft.reason}
                          onChange={(e) => setProposedLines((cur) => ({ ...cur, [item.id]: { ...draft, reason: e.target.value } }))}
                          error={lineErrors[`${item.id}:reason`]}
                          fieldSize="sm"
                          placeholder="e.g. Resin cost increase"
                          aria-label={`Reason for ${item.part_number}`}
                        />
                      </Td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {proposeError && <p className="text-sm text-danger-fg">{proposeError}</p>}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Proposed delivery date (optional)"
              type="date"
              value={proposeDeliveryDate}
              onChange={(e) => setProposeDeliveryDate(e.target.value)}
            />
          </div>
          <Textarea
            label="Notes (optional)"
            value={proposeNotes}
            onChange={(e) => setProposeNotes(e.target.value)}
            rows={3}
            maxLength={500}
          />
        </div>
        <ModalFooter>
          <Button variant="secondary" size="sm" onClick={() => setProposeOpen(false)} disabled={respondMut.isPending}>
            Cancel
          </Button>
          <Button
            variant="primary"
            size="sm"
            icon={<LuPencil size={14} />}
            loading={respondMut.isPending}
            disabled={respondMut.isPending}
            onClick={submitPropose}
          >
            Send proposal
          </Button>
        </ModalFooter>
      </Modal>

      <ReasonDialog
        isOpen={declineOpen}
        onClose={() => setDeclineOpen(false)}
        onConfirm={(reason) => respondMut.mutate({ type: 'decline', notes: reason })}
        title="Decline this purchase order?"
        description="Tell OGAMI why you cannot fulfil this order. This is recorded on the PO and sent to the purchasing team."
        reasonLabel="Reason for declining"
        reasonPlaceholder="e.g. Material unavailable until next quarter"
        minLength={10}
        confirmLabel="Decline PO"
        variant="danger"
        pending={respondMut.isPending}
      />
    </div>
  );
}
