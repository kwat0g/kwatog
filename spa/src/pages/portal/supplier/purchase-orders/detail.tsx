import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { useState } from 'react';
import {
  LuCircleCheck,
  LuTruck,
  LuFileDown,
  LuFileText,
  LuSend,
  LuPencil,
  LuThumbsDown,
} from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type {
  PortalShippingDocument,
  RespondToPurchaseOrderPayload,
  PortalShipment,
} from '@/types/b2b';
import type { PurchaseOrderResponseStatus, PurchaseOrderResponseType } from '@/types/purchasing';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { FileInput } from '@/components/ui/FileInput';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatCard } from '@/components/ui/StatCard';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { formatDate, localIsoDate } from '@/lib/formatDate';
import { overDispatchedLines, remainingDispatchQuantities } from '@/lib/supplierDispatch';
import { reportMutationError } from '@/lib/formErrors';
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
const responseStatusVariant: Record<
  PurchaseOrderResponseStatus,
  'neutral' | 'warning' | 'success' | 'danger'
> = {
  pending: 'warning',
  accepted: 'success',
  rejected: 'danger',
  superseded: 'neutral',
  pending_approval: 'warning',
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
  const [editingShipmentId, setEditingShipmentId] = useState<string | null>(null);
  const [showInvoiceForm, setShowInvoiceForm] = useState(false);
  const [trackingNumber, setTrackingNumber] = useState('');
  const [shippedDate, setShippedDate] = useState('');
  const [carrier, setCarrier] = useState('');
  const [estimatedArrival, setEstimatedArrival] = useState('');
  const [shipmentNotes, setShipmentNotes] = useState('');
  const [shipmentQuantities, setShipmentQuantities] = useState<Record<string, string>>({});
  const [dispatchPhoto, setDispatchPhoto] = useState<File | null>(null);
  const [replacementReturnId, setReplacementReturnId] = useState('');
  const [photoShipmentId, setPhotoShipmentId] = useState<string | null>(null);
  const [legacyPhoto, setLegacyPhoto] = useState<File | null>(null);

  const [billNumber, setBillNumber] = useState('');
  const [billDate, setBillDate] = useState('');
  const [selectedBillIds, setSelectedBillIds] = useState<string[]>([]);
  const [invoiceFile, setInvoiceFile] = useState<File | null>(null);
  const [billRemarks, setBillRemarks] = useState('');

  const [acceptOpen, setAcceptOpen] = useState(false);
  const [acceptNotes, setAcceptNotes] = useState('');
  const [declineOpen, setDeclineOpen] = useState(false);
  const [proposeOpen, setProposeOpen] = useState(false);
  const [proposeDeliveryDate, setProposeDeliveryDate] = useState('');
  const [proposeNotes, setProposeNotes] = useState('');
  const [proposedLines, setProposedLines] = useState<
    Record<string, { quantity: string; unit_price: string; reason: string }>
  >({});
  const [lineErrors, setLineErrors] = useState<Record<string, string>>({});
  const [proposeError, setProposeError] = useState<string | null>(null);

  const {
    data: po,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'supplier', 'po', id],
    queryFn: () => supplierPortalApi.getPo(id!),
    enabled: !!id,
  });
  const fallbackInvoiceGrn = (po?.invoiceable_bill_portions?.length ?? 0) === 0
    ? po?.goods_receipt_notes.find((grn) => grn.can_invoice)
    : undefined;
  const selectedInvoicePortions = (po?.invoiceable_bill_portions ?? [])
    .filter((portion) => selectedBillIds.includes(portion.id));
  const invoiceGrossTotal = selectedBillIds.length > 0 && selectedInvoicePortions.length === selectedBillIds.length
    ? (selectedInvoicePortions.reduce((sum, portion) => sum + Math.round(Number(portion.gross_total) * 100), 0) / 100).toFixed(2)
    : '';
  const draftGrnCount = po?.goods_receipt_notes.filter((grn) => grn.status === 'draft').length ?? 0;

  const { data: shippingDocs, isError: shippingDocsFailed, refetch: refetchDocs } = useQuery({
    queryKey: ['portal', 'supplier', 'po', id, 'shipping-documents'],
    queryFn: () => supplierPortalApi.listShippingDocuments(id!),
    enabled: !!id,
  });

  const respondMut = useMutation({
    mutationFn: (payload: RespondToPurchaseOrderPayload) =>
      supplierPortalApi.respondToPurchaseOrder(id!, payload),
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
    mutationFn: () => {
      const editing = po?.shipments.find((shipment) => shipment.id === editingShipmentId);
      const payload = {
        shipped_date: editing && shippedDate === (editing.shipped_date ?? '') ? undefined : shippedDate || undefined,
        carrier: carrier.trim() || undefined,
        tracking_number: trackingNumber.trim() || undefined,
        estimated_arrival: editing && estimatedArrival === (editing.estimated_arrival ?? '') ? undefined : estimatedArrival || undefined,
        notes: shipmentNotes.trim() || undefined,
      };
      if (editingShipmentId) {
        return supplierPortalApi.updateShipmentById(id!, editingShipmentId, {
          ...payload,
          ...(!editing?.quantity_recorded ? { items: Object.entries(shipmentQuantities)
            .filter(([, quantity]) => quantity.trim() !== '')
            .map(([purchase_order_item_id, quantity_shipped]) => ({ purchase_order_item_id, quantity_shipped })) } : {}),
        });
      } else {
        const form = new FormData();
        form.append('shipped_date', shippedDate);
        form.append('estimated_arrival', estimatedArrival);
        if (payload.carrier) form.append('carrier', payload.carrier);
        if (payload.tracking_number) form.append('tracking_number', payload.tracking_number);
        if (payload.notes) form.append('notes', payload.notes);
        if (replacementReturnId) form.append('return_request_id', replacementReturnId);
        Object.entries(shipmentQuantities)
          .filter(([, quantity]) => quantity.trim() !== '')
          .forEach(([itemId, quantity], index) => {
            form.append(`items[${index}][purchase_order_item_id]`, itemId);
            form.append(`items[${index}][quantity_shipped]`, quantity);
          });
        form.append('dispatch_photo', dispatchPhoto!);
        return supplierPortalApi.createShipment(id!, form);
      }
    },
    onSuccess: () => {
      toast.success(editingShipmentId ? 'Shipment updated.' : 'Shipment added.');
      setShowShipmentForm(false);
      setEditingShipmentId(null);
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'po', id] });
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'po', id, 'shipping-documents'] });
    },
    onError: (error) => reportMutationError(error, 'Could not save shipment details.'),
  });

  const backfillPhotoMut = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.append('document_type', 'dispatch_photo');
      form.append('supplier_shipment_id', photoShipmentId!);
      form.append('file', legacyPhoto!);
      return supplierPortalApi.uploadShippingDocument(id!, form);
    },
    onSuccess: () => {
      toast.success('Dispatch photo attached.');
      setPhotoShipmentId(null);
      setLegacyPhoto(null);
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'po', id, 'shipping-documents'] });
    },
    onError: (error) => reportMutationError(error, 'Could not attach the dispatch photo.'),
  });

  const submitInvoiceMut = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.append('bill_number', billNumber);
      form.append('date', billDate);
      if (selectedBillIds.length > 0) {
        selectedBillIds.forEach((billId) => form.append('bill_ids[]', billId));
        form.append('gross_total', invoiceGrossTotal);
      } else if (fallbackInvoiceGrn) {
        form.append('goods_receipt_note_id', fallbackInvoiceGrn.id);
      }
      if (invoiceFile) form.append('file', invoiceFile);
      if (billRemarks) form.append('remarks', billRemarks);
      return supplierPortalApi.submitInvoice(id!, form);
    },
    onSuccess: (res) => {
      toast.success(res.message ?? 'Invoice submitted.');
      setShowInvoiceForm(false);
      setBillNumber('');
      setBillDate('');
      setSelectedBillIds([]);
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
  const canAccept = po?.capabilities.can_accept ?? false;
  const canPropose = po?.capabilities.can_propose ?? false;
  const canDecline = po?.capabilities.can_decline ?? false;
  const canUpdateShipment = po?.capabilities.can_update_shipment ?? false;
  const canEditAnyShipment = canUpdateShipment && (po?.shipments ?? []).some((shipment) => shipment.can_edit !== false);
  const canUploadDocument = po?.capabilities.can_upload_document ?? false;
  const canSubmitInvoice = po?.capabilities.can_submit_invoice ?? false;
  const pendingReplacementReturns = po?.supplier_returns?.filter((item) => item.replacement_pending) ?? [];
  const hasUnquantifiedShipment = po?.shipments.some((shipment) => !shipment.quantity_recorded) ?? false;
  const editingNeedsQuantities = po?.shipments.some((shipment) => shipment.id === editingShipmentId && !shipment.quantity_recorded) ?? false;
  const recordingQuantities = !editingShipmentId || editingNeedsQuantities;
  const remainingByItem = po ? remainingDispatchQuantities(po.shipments, po.items.map((item) => ({
    id: item.id, quantity: item.quantity_dispatch_limit,
  }))) : new Map<string, number>();
  const hasRemainingToDispatch = [...remainingByItem.values()].some((quantity) => quantity > 0);
  const shipmentWouldBePartial = po?.items.some((item) =>
    (remainingByItem.get(item.id) ?? 0) > Number(shipmentQuantities[item.id] || 0)) ?? false;
  const overDispatched = po ? overDispatchedLines(po.shipments, po.items.map((item) => ({
    id: item.id, quantity: item.quantity_dispatch_limit,
  }))) : [];

  const openAccept = () => {
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
    if (items.length === 0 && (!proposeDeliveryDate || proposeDeliveryDate === po?.expected_delivery_date)) {
      setProposeError('Change the delivery date or at least one line before sending a proposal.');
      return;
    }
    setProposeError(null);
    respondMut.mutate({
      type: 'propose',
      proposed_delivery_date: proposeDeliveryDate !== po?.expected_delivery_date ? proposeDeliveryDate || undefined : undefined,
      notes: proposeNotes.trim() || undefined,
      items,
    });
  };

  const openInvoiceForm = () => {
    setBillDate(localIsoDate());
    const first = po?.invoiceable_bill_portions?.[0];
    setSelectedBillIds(first ? [first.id] : []);
    setShowInvoiceForm(true);
  };

  const toggleInvoicePortion = (billId: string) => {
    const next = selectedBillIds.includes(billId)
      ? selectedBillIds.filter((id) => id !== billId)
      : [...selectedBillIds, billId];
    setSelectedBillIds(next);
  };

  const openAddShipmentForm = () => {
    setShippedDate(localIsoDate());
    setCarrier('');
    setTrackingNumber('');
    setEstimatedArrival('');
    setShipmentNotes('');
    setShipmentQuantities({});
    setDispatchPhoto(null);
    setReplacementReturnId(pendingReplacementReturns.length === 1 ? pendingReplacementReturns[0].id : '');
    setEditingShipmentId(null);
    setShowShipmentForm(true);
  };

  const openEditShipmentForm = (shipment: PortalShipment) => {
    setShipmentQuantities({});
    setShippedDate(shipment.shipped_date ?? '');
    setCarrier(shipment.carrier ?? '');
    setTrackingNumber(shipment.tracking_number ?? '');
    setEstimatedArrival(shipment.estimated_arrival ?? '');
    setShipmentNotes(shipment.notes ?? '');
    setEditingShipmentId(shipment.id);
    setShowShipmentForm(true);
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
        actions={
          po ? (
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                icon={<LuFileDown size={14} />}
                onClick={downloadPdf}
              >
                PDF
              </Button>
              {canAccept && (
                <Button
                  variant="primary"
                  size="sm"
                  icon={<LuCircleCheck size={14} />}
                  onClick={openAccept}
                  disabled={respondMut.isPending}
                  loading={respondMut.isPending && acceptOpen}
                >
                  Accept
                </Button>
              )}
              {canPropose && (
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuPencil size={14} />}
                  onClick={openPropose}
                  disabled={respondMut.isPending}
                >
                  Propose changes
                </Button>
              )}
              {canDecline && (
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuThumbsDown size={14} />}
                  onClick={() => setDeclineOpen(true)}
                  disabled={respondMut.isPending}
                >
                  Decline
                </Button>
              )}
              {canUpdateShipment && hasRemainingToDispatch && (
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuTruck size={14} />}
                  onClick={openAddShipmentForm}
                  disabled={hasUnquantifiedShipment}
                  title={hasUnquantifiedShipment ? 'Record quantities on the earlier shipment first' : undefined}
                >
                  Add shipment
                </Button>
              )}
              {canSubmitInvoice && (
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuSend size={14} />}
                  onClick={openInvoiceForm}
                >
                  Submit invoice
                </Button>
              )}
            </div>
          ) : undefined
        }
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load purchase order"
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {!isLoading && !isError && !po && (
          <EmptyState icon="file-x" title="Purchase order not found" />
        )}

        {!isLoading && !isError && po && (
          <>
            {(po.status === 'sent' || po.status === 'supplier_proposed') && (
              <Panel>
                <p className="text-sm text-secondary">
                  Accept this purchase order to report shipments and upload documents.
                </p>
              </Panel>
            )}
            {po.status === 'supplier_declined' && !canAccept && !canPropose && !canDecline && (
              <Panel>
                <p className="text-sm text-secondary">
                  You declined this order. OGAMI is reviewing it.
                </p>
              </Panel>
            )}
            {po.status === 'cancelled' && (
              <Panel>
                <p className="text-sm text-secondary">
                  OGAMI cancelled this purchase order. No further deliveries or invoices should be
                  made against it.
                </p>
              </Panel>
            )}
            {canUpdateShipment && (
              <p className="text-sm text-secondary" aria-label="Supplier fulfillment actions">
                Add a shipment after dispatch. Include its quantity, expected arrival, and a photo of the dispatched items. OGAMI confirms receipt in a GRN.
              </p>
            )}
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
              <StatCard
                label="Received GRNs"
                value={po.goods_receipt_notes.length - draftGrnCount}
                helper={`${draftGrnCount} expected GRN${draftGrnCount === 1 ? '' : 's'}`}
              />
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
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                        Status
                      </div>
                      <Chip variant={responseStatusVariant[po.latest_response.status]}>
                        {po.latest_response.status.replace(/_/g, ' ')}
                      </Chip>
                    </div>
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                        Proposed delivery
                      </div>
                      <div className="font-mono">
                        {po.latest_response.proposed_delivery_date
                          ? formatDate(po.latest_response.proposed_delivery_date)
                          : '—'}
                      </div>
                    </div>
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                        Responded
                      </div>
                      <div className="font-mono">
                        {po.latest_response.responded_at
                          ? formatDate(po.latest_response.responded_at)
                          : '—'}
                      </div>
                    </div>
                  </div>
                  {po.latest_response.notes && (
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                        Notes
                      </div>
                      <p className="text-secondary">{po.latest_response.notes}</p>
                    </div>
                  )}
                  {po.latest_response.resolution_notes && (
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                        OGAMI response
                      </div>
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
                              <tr
                                key={`${line.purchase_order_item_id}-${line.proposed_quantity ?? ''}-${line.proposed_unit_price ?? ''}`}
                                className={trCls}
                              >
                                <Td>
                                  <span className="font-mono text-muted">
                                    {item?.part_number ?? '—'}
                                  </span>
                                  {item ? ` · ${item.name}` : ''}
                                </Td>
                                <Td align="right" mono>
                                  {line.proposed_quantity ?? '—'}
                                </Td>
                                <Td align="right" mono>
                                  {line.proposed_unit_price
                                    ? formatPeso(line.proposed_unit_price)
                                    : '—'}
                                </Td>
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

            <div className="space-y-4">
              {(po.supplier_returns?.length ?? 0) > 0 && (
                <Panel title="Quality returns" meta={String(po.supplier_returns?.length ?? 0)}>
                  <p className="mb-3 text-sm text-secondary">OGAMI separated these defective units. Return details and replacement status appear below.</p>
                  {!canSubmitInvoice && (po.invoiceable_bill_portions?.length ?? 0) > 0 && pendingReplacementReturns.length > 0 && (
                    <p className="mb-3 text-sm text-secondary">Submit invoice becomes available after the replacement pieces pass QC on a new GRN.</p>
                  )}
                  <div className="space-y-3">
                    {po.supplier_returns?.map((item) => (
                      <div key={item.rma_number} className="rounded-md border border-subtle p-3 text-sm">
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1"><strong className="font-mono">{item.rma_number}</strong><Chip variant={chipVariantForStatus(item.status)}>{item.status_label}</Chip><span>{item.items.map((line) => `${line.part_number ?? 'Item'}: ${formatQuantity(line.quantity)} ${line.unit ?? ''}`).join(' · ')}</span></div>
                        {item.problem && <p className="mt-2">Problem: {item.problem}</p>}
                        {item.replacement_purchase_order ? (
                          <p className="mt-2">Ship replacements against {item.replacement_purchase_order.po_number}.</p>
                        ) : item.replacement_due_date && item.status === 'completed' ? (
                          <p className="mt-2">{item.replacement_pending
                            ? `Ship replacements against ${po.po_number} by ${formatDate(item.replacement_due_date)}.`
                            : 'Replacement received and accepted.'}</p>
                        ) : null}
                        {item.defect_photo_name && <Button size="sm" variant="secondary" className="mt-2" onClick={async () => {
                          try {
                            const blob = await supplierPortalApi.downloadReturnDefectPhoto(id!, item.id);
                            downloadBlob(blob, item.defect_photo_name!);
                          } catch { toast.error('Could not download the defect photo.'); }
                        }}>Download defect photo</Button>}
                      </div>
                    ))}
                  </div>
                </Panel>
              )}
              <Panel title="Shipments" meta={String(po.shipments?.length ?? 0)} noPadding>
                {po.shipments && po.shipments.length > 0 ? (
                  <div className="overflow-x-auto">
                    {hasUnquantifiedShipment && <p role="alert" className="px-4 py-2 text-sm text-warning-fg">Record quantities on earlier shipments before adding another.</p>}
                    {overDispatched.length > 0 && <p role="alert" className="px-4 py-2 text-sm text-warning-fg">
                      Recorded dispatch exceeds ordered quantity for {overDispatched.map((line) => po.items.find((item) => item.id === line.id)?.part_number ?? 'PO item').join(', ')}. Confirm with Purchasing before another shipment.
                    </p>}
                    <table className={tableCls}>
                      <thead>
                        <tr className={theadTrCls}>
                          <Th>Shipped date</Th>
                          <Th>Carrier</Th>
                          <Th>Tracking</Th>
                          <Th>ETA</Th>
                          <Th>Dispatched</Th>
                          <Th>Dispatch photo</Th>
                          <Th>Updated</Th>
                          {canEditAnyShipment && <Th align="right">Action</Th>}
                        </tr>
                      </thead>
                      <tbody>
                        {po.shipments.map((shipment) => {
                          const photo = shippingDocs?.find((doc) => doc.document_type === 'dispatch_photo' && doc.supplier_shipment_id === shipment.id);
                          return (
                          <tr key={shipment.id} className={trCls}>
                            <Td mono className="text-muted">
                              {shipment.shipped_date ? formatDate(shipment.shipped_date) : '—'}
                            </Td>
                            <Td>{shipment.carrier ?? '—'}</Td>
                            <Td mono className="text-muted">
                              {shipment.tracking_number ?? '—'}
                            </Td>
                            <Td mono className="text-muted">
                              {shipment.estimated_arrival
                                ? formatDate(shipment.estimated_arrival)
                                : '—'}
                              {shipment.late_eta && <div className="text-warning-fg text-xs">At risk: ETA after confirmed date</div>}
                            </Td>
                            <Td>{shipment.quantity_recorded ? shipment.items?.map((line) => {
                              const item = po.items.find((candidate) => candidate.id === line.purchase_order_item_id);
                              return <div key={line.purchase_order_item_id}>{item?.part_number ?? 'Item'}: {line.quantity_shipped} {item?.unit ?? ''}</div>;
                            }) : 'Quantity not recorded'}
                              {shipment.notes && <div className="text-xs text-muted">Reason / notes: {shipment.notes}</div>}
                              {shipment.return_rma_number && <div className="text-xs text-muted">Replacement for {shipment.return_rma_number}</div>}
                            </Td>
                            <Td>
                              {photo ? <Button variant="ghost" size="sm" onClick={() => void downloadShippingDoc(photo)}>Download photo</Button>
                                : shippingDocs ? (canUploadDocument
                                  ? <Button variant="ghost" size="sm" onClick={() => { setPhotoShipmentId(shipment.id); setLegacyPhoto(null); }}>Add photo</Button>
                                  : <span className="text-muted text-xs">Not attached</span>)
                                  : <span className="text-muted text-xs">—</span>}
                            </Td>
                            <Td mono className="text-muted text-xs">
                              {shipment.updated_at ? formatDate(shipment.updated_at) : '—'}
                            </Td>
                            {canEditAnyShipment && (
                              <Td align="right">
                                {shipment.can_edit !== false && <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => openEditShipmentForm(shipment)}
                                >
                                  {shipment.quantity_recorded ? 'Edit' : 'Record quantities'}
                                </Button>}
                              </Td>
                            )}
                          </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <p className="text-sm text-muted px-4 py-2">No shipments yet.</p>
                )}
              </Panel>

              {shippingDocsFailed && <div role="alert" className="text-sm text-danger-fg">
                Shipping documents could not be loaded. <Button variant="secondary" size="sm" onClick={() => void refetchDocs()}>Retry documents</Button>
              </div>}
              {shippingDocs && shippingDocs.some((doc) => doc.document_type !== 'dispatch_photo' || !doc.supplier_shipment_id) && (
                <Panel title="Shipping documents" meta={String(shippingDocs.filter((doc) => doc.document_type !== 'dispatch_photo' || !doc.supplier_shipment_id).length)}>
                  <div className="divide-y divide-subtle">
                    {shippingDocs.filter((doc) => doc.document_type !== 'dispatch_photo' || !doc.supplier_shipment_id).map((doc) => (
                      <div
                        key={doc.id}
                        className="flex items-center justify-between py-2 first:pt-0 last:pb-0"
                      >
                        <div className="flex items-center gap-2.5 min-w-0">
                          <LuFileText size={14} className="text-muted shrink-0" />
                          <div className="min-w-0">
                            <p className="text-xs font-medium truncate">{doc.original_filename}</p>
                            <p className="text-2xs text-muted">
                              {doc.document_type_label} · {doc.file_size_formatted} · {doc.supplier_shipment_id ? po.shipments.find((shipment) => shipment.id === doc.supplier_shipment_id)?.tracking_number ?? 'Shipment' : 'PO-wide'}
                            </p>
                          </div>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => void downloadShippingDoc(doc)}
                        >
                          Download
                        </Button>
                      </div>
                    ))}
                  </div>
                </Panel>
              )}
            </div>

            {showShipmentForm && canUpdateShipment && (
              <Modal
                isOpen
                onClose={() => { if (!shipmentMut.isPending) setShowShipmentForm(false); }}
                title={editingNeedsQuantities ? 'Record shipment quantities' : editingShipmentId ? 'Edit shipment' : 'Add shipment'}
                size="xl"
                closeOnOverlayClick={!shipmentMut.isPending}
              >
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    if (recordingQuantities) {
                      const entered = Object.entries(shipmentQuantities).filter(([, quantity]) => quantity.trim() !== '');
                      if (!entered.some(([, quantity]) => DECIMAL_RE.test(quantity) && Number(quantity) > 0)) {
                        toast.error('Enter a positive shipped quantity for at least one PO item.');
                        return;
                      }
                      if (entered.some(([itemId, quantity]) => !DECIMAL_RE.test(quantity) || Number(quantity) <= 0
                        || Math.round(Number(quantity) * 100) > Math.round((remainingByItem.get(itemId) ?? 0) * 100))) {
                        toast.error('Shipped quantity cannot exceed remaining ordered quantity.');
                        return;
                      }
                      if (shipmentWouldBePartial && !shipmentNotes.trim()) {
                        toast.error('Enter a reason for the partial shipment.');
                        return;
                      }
                    }
                    if (!editingShipmentId && !dispatchPhoto) {
                      toast.error('Add a photo of the dispatched items.');
                      return;
                    }
                    shipmentMut.mutate();
                  }}
                  className="flex flex-col gap-4"
                >
                  <p className="text-sm text-secondary">Record goods already dispatched. The photo documents this shipment; OGAMI confirms actual receipt separately.</p>
                  {!editingShipmentId && pendingReplacementReturns.length > 0 && (
                    <Select label="Shipment purpose" value={replacementReturnId} onChange={(event) => setReplacementReturnId(event.target.value)}>
                      <option value="">Regular PO delivery</option>
                      {pendingReplacementReturns.map((item) => <option key={item.id} value={item.id}>Replacement for {item.rma_number}</option>)}
                    </Select>
                  )}
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <Input
                      label="Shipped date"
                      type="date"
                      required={!editingShipmentId}
                      value={shippedDate}
                      readOnly
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
                      required={!editingShipmentId}
                      value={estimatedArrival}
                      onChange={(e) => setEstimatedArrival(e.target.value)}
                    />
                  </div>
                  {recordingQuantities && <div className="space-y-2">
                    <p className="text-xs text-muted">Record quantity dispatched in the PO unit. OGAMI records receipt separately.</p>
                    {po.items.map((item) => {
                      const remaining = Math.max(0, remainingByItem.get(item.id) ?? 0);
                      const entered = shipmentQuantities[item.id] ?? '';
                      return <Input
                        key={item.id}
                        label={`${item.part_number} · ${item.name} (${item.unit ?? 'units'})`}
                        aria-label={`Shipped quantity for ${item.part_number}`}
                        type="text"
                        inputMode="decimal"
                        placeholder="Shipped quantity"
                        value={entered}
                        onChange={(e) => setShipmentQuantities((current) => ({ ...current, [item.id]: e.target.value }))}
                        pattern="^\d+(\.\d{1,2})?$"
                        disabled={remaining <= 0}
                        helper={`Remaining to dispatch: ${formatQuantity(remaining)} ${item.unit ?? 'units'}`}
                        error={entered && DECIMAL_RE.test(entered) && Math.round(Number(entered) * 100) > Math.round(remaining * 100)
                          ? `Maximum ${formatQuantity(remaining)} ${item.unit ?? 'units'}` : undefined}
                      />;
                    })}
                  </div>}
                  {!editingShipmentId && <FileInput
                    label="Photo of dispatched items"
                    helper="Required · Take a photo or choose JPG, PNG, or WebP (maximum 10 MB)."
                    accept="image/jpeg,image/png,image/webp"
                    capture="environment"
                    required
                    onChange={(e) => setDispatchPhoto(e.target.files?.[0] ?? null)}
                  />}
                  <Textarea
                    label={recordingQuantities && shipmentWouldBePartial ? 'Reason for partial shipment' : 'Notes (optional)'}
                    value={shipmentNotes}
                    onChange={(e) => setShipmentNotes(e.target.value)}
                    required={recordingQuantities && shipmentWouldBePartial}
                    rows={2}
                    maxLength={500}
                  />
                  <ModalFooter>
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      disabled={shipmentMut.isPending}
                      onClick={() => {
                        setShowShipmentForm(false);
                        setEditingShipmentId(null);
                      }}
                    >
                      Cancel
                    </Button>
                    <Button
                      type="submit"
                      variant="primary"
                      size="sm"
                      loading={shipmentMut.isPending}
                    >
                      {editingNeedsQuantities ? 'Record quantities' : editingShipmentId ? 'Save changes' : 'Add shipment'}
                    </Button>
                  </ModalFooter>
                </form>
              </Modal>
            )}

            <Modal
              isOpen={!!photoShipmentId && canUploadDocument}
              onClose={() => { if (!backfillPhotoMut.isPending) setPhotoShipmentId(null); }}
              title="Add dispatch photo"
              size="md"
              closeOnOverlayClick={!backfillPhotoMut.isPending}
            >
              <form onSubmit={(event) => { event.preventDefault(); if (legacyPhoto) backfillPhotoMut.mutate(); }}>
                <p className="text-sm text-secondary mb-4">Attach a photo to this earlier shipment. OGAMI records actual receipt separately.</p>
                <FileInput
                  label="Photo of dispatched items"
                  helper="JPG, PNG, or WebP · maximum 10 MB"
                  accept="image/jpeg,image/png,image/webp"
                  capture="environment"
                  required
                  onChange={(event) => setLegacyPhoto(event.target.files?.[0] ?? null)}
                />
                <ModalFooter>
                  <Button type="button" variant="secondary" size="sm" disabled={backfillPhotoMut.isPending} onClick={() => setPhotoShipmentId(null)}>Cancel</Button>
                  <Button type="submit" variant="primary" size="sm" loading={backfillPhotoMut.isPending}>Attach photo</Button>
                </ModalFooter>
              </form>
            </Modal>

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
                        <Th align="right">Accepted</Th>
                        <Th align="right">Remaining</Th>
                        <Th align="right">Unit Price</Th>
                        <Th align="right">Total</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {po.items.map((item) => (
                        <tr key={item.id} className={trCls}>
                          <Td mono className="text-muted">
                            {item.part_number}
                          </Td>
                          <Td>{item.name}</Td>
                          <Td align="right" mono>
                            {item.quantity_ordered}
                          </Td>
                          <Td align="right" mono>
                            {item.quantity_received}
                          </Td>
                          <Td align="right" mono>
                            {item.quantity_accepted}
                          </Td>
                          <Td align="right" mono>
                            {item.quantity_remaining}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(item.unit_price)}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(item.total_price)}
                          </Td>
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
              <Panel
                title="Goods Receipt Notes"
                meta={String(po.goods_receipt_notes.length)}
                noPadding
              >
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>GRN #</Th>
                        <Th>Received Date</Th>
                        <Th>Status</Th>
                        <Th>Invoice</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {po.goods_receipt_notes.map((grn) => (
                        <tr key={grn.id} className={trCls}>
                          <Td mono>{grn.grn_number}</Td>
                          <Td className="text-muted">
                            {grn.received_date ? formatDate(grn.received_date) : '—'}
                          </Td>
                          <Td>
                            <Chip variant={chipVariantForStatus(grn.status)}>
                              {grn.status_label ?? grn.status.replace(/_/g, ' ')}
                            </Chip>
                          </Td>
                          <Td className="text-muted text-sm">
                            {grn.supplier_invoice_number ? (
                              <>Invoiced as {grn.supplier_invoice_number}</>
                            ) : (
                              'Not invoiced'
                            )}
                          </Td>
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
                        <Th>Supplier Invoice #</Th>
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
                          <Td mono className="text-accent">
                            {bill.bill_number}
                          </Td>
                          <Td mono className="text-muted">
                            {bill.supplier_invoice_number ?? '—'}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(bill.total_amount)}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(bill.paid_amount)}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(bill.balance)}
                          </Td>
                          <Td className="text-muted">
                            {bill.due_date ? formatDate(bill.due_date) : '—'}
                          </Td>
                          <Td>
                            <Chip variant={chipVariantForStatus(bill.status)}>
                              {bill.status_label ?? bill.status}
                            </Chip>
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

      {po && (
        <Modal
          isOpen={showInvoiceForm && canSubmitInvoice}
          onClose={() => { if (!submitInvoiceMut.isPending) setShowInvoiceForm(false); }}
          title="Submit invoice"
          size="lg"
        >
          <form
            onSubmit={(e) => {
              e.preventDefault();
              submitInvoiceMut.mutate();
            }}
            className="flex flex-col gap-3"
          >
            <p className="text-sm text-secondary">Send your invoice for {po.po_number} to OGAMI for AP review.</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                readOnly
              />
            </div>
            <div className="rounded-md border border-default p-3 space-y-2">
              <p className="text-xs font-medium text-primary">Accepted receipt portions</p>
              {(po.invoiceable_bill_portions ?? []).length === 0 && fallbackInvoiceGrn && (
                <p className="text-sm">{fallbackInvoiceGrn.grn_number} · AP will stage its accepted receipt bill.</p>
              )}
              {(po.invoiceable_bill_portions ?? []).map((portion) => (
                <label key={portion.id} className="flex items-center gap-3 text-sm">
                  {po.invoiceable_bill_portions!.length > 1 && (
                    <input type="checkbox" checked={selectedBillIds.includes(portion.id)}
                      onChange={() => toggleInvoicePortion(portion.id)} />
                  )}
                  <span className="font-mono">{portion.grn_number}</span>
                  <span className="text-secondary">{formatQuantity(portion.quantity)} pcs</span>
                  <span className="ml-auto font-mono">{formatPeso(portion.gross_total)}</span>
                </label>
              ))}
            </div>
            {selectedBillIds.length > 0 && (
              <Input label="Invoiceable total (before withholding tax)" type="text" readOnly
                value={formatPeso(invoiceGrossTotal)} className="font-mono tabular-nums"
                helper="Calculated from selected receipts. If your invoice differs, contact OGAMI before submitting." />
            )}
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
              Due date follows your payment terms; VAT follows the purchase order.
            </p>
            <ModalFooter>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                disabled={submitInvoiceMut.isPending}
                onClick={() => setShowInvoiceForm(false)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                variant="primary"
                size="sm"
                icon={<LuSend size={14} />}
                disabled={!billNumber || !billDate || (selectedBillIds.length > 0 ? !invoiceGrossTotal : !fallbackInvoiceGrn)}
                loading={submitInvoiceMut.isPending}
              >
                Submit invoice
              </Button>
            </ModalFooter>
          </form>
        </Modal>
      )}

      <Modal
        isOpen={acceptOpen}
        onClose={() => (respondMut.isPending ? undefined : setAcceptOpen(false))}
        title="Accept purchase order"
        size="md"
      >
        <div className="space-y-4 py-2">
          <p className="text-sm text-secondary">
            Accepting confirms the order as written, including its required delivery date. To request
            another date, use Propose changes.
          </p>
          <div className="text-sm">
            <span className="text-muted">Required delivery date</span>
            <div className="font-medium">{po?.expected_delivery_date ? formatDate(po.expected_delivery_date) : '—'}</div>
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
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setAcceptOpen(false)}
            disabled={respondMut.isPending}
          >
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
            Change the delivery date, order lines, or both. Only changed lines are sent. Explain each
            line change.
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Proposed delivery date (optional)"
              helper="Change this date if you cannot meet the required delivery date."
              type="date"
              value={proposeDeliveryDate}
              onChange={(e) => setProposeDeliveryDate(e.target.value)}
            />
          </div>
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
                  const draft = proposedLines[item.id] ?? {
                    quantity: item.quantity_ordered,
                    unit_price: item.unit_price,
                    reason: '',
                  };
                  return (
                    <tr key={item.id} className={trCls}>
                      <Td>
                        <span className="font-mono text-muted">{item.part_number}</span>
                        <div className="text-2xs text-muted">{item.name}</div>
                      </Td>
                      <Td align="right" mono>
                        {item.quantity_ordered}
                      </Td>
                      <Td align="right">
                        <Input
                          type="text"
                          inputMode="decimal"
                          value={draft.quantity}
                          onChange={(e) =>
                            setProposedLines((cur) => ({
                              ...cur,
                              [item.id]: { ...draft, quantity: e.target.value },
                            }))
                          }
                          error={lineErrors[`${item.id}:quantity`]}
                          fieldSize="sm"
                          className="text-right font-mono w-24"
                          aria-label={`Proposed quantity for ${item.part_number}`}
                        />
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(item.unit_price)}
                      </Td>
                      <Td align="right">
                        <Input
                          type="text"
                          inputMode="decimal"
                          value={draft.unit_price}
                          onChange={(e) =>
                            setProposedLines((cur) => ({
                              ...cur,
                              [item.id]: { ...draft, unit_price: e.target.value },
                            }))
                          }
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
                          onChange={(e) =>
                            setProposedLines((cur) => ({
                              ...cur,
                              [item.id]: { ...draft, reason: e.target.value },
                            }))
                          }
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
          <Textarea
            label="Notes (optional)"
            value={proposeNotes}
            onChange={(e) => setProposeNotes(e.target.value)}
            rows={3}
            maxLength={500}
          />
        </div>
        <ModalFooter>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setProposeOpen(false)}
            disabled={respondMut.isPending}
          >
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
