/** Sprint 7 / ADV7 — Delivery detail with Proof-of-Delivery management. */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import {
  LuCheck,
  LuTag,
  LuTrash2,
  LuFileText,
  LuFileDown,
  LuShieldCheck,
  LuArchiveRestore,
  LuTriangleAlert,
} from '@/lib/icons';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { downloadAuthenticatedFile } from '@/api/download';
import { deliveriesApi, deliveryProofsApi } from '@/api/supply-chain';
import { invoicesApi } from '@/api/accounting/invoices';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { Input } from '@/components/ui/Input';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { buildDeliveryChain } from '@/lib/chains';
import { usePermission } from '@/hooks/usePermission';
import { useChainProgress } from '@/hooks/useChainProgress';
import type { DeliveryStatus } from '@/types/supplyChain';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { Textarea } from '@/components/ui/Textarea';
import { LinkButton } from '@/components/ui/LinkButton';
import { focusRingInset } from '@/lib/focus';
import { deliveryStatusVariant as STATUS_CHIP } from '@/lib/statusVariants';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { DeliveryAttemptPanel } from './DeliveryAttemptPanel';
import { TruckReturnReceiptPanel } from './TruckReturnReceiptPanel';
import { DeliveryStockCostPanel } from './DeliveryStockCostPanel';
import { ShipmentPlanDialog } from './ShipmentPlanDialog';
import { ShipToAddress } from '@/components/supply-chain/ShipToAddress';

export default function DeliveryDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();

  const [cancelDeliveryOpen, setCancelDeliveryOpen] = useState(false);
  const [confirmModalOpen, setConfirmModalOpen] = useState(false);
  const [receiverName, setReceiverName] = useState('');
  const [receiverPosition, setReceiverPosition] = useState('');
  const [confirmRemarks, setConfirmRemarks] = useState('');
  const [deleteProofId, setDeleteProofId] = useState<string | null>(null);
  const [restoreProofId, setRestoreProofId] = useState<string | null>(null);
  const [finalizeInvoiceId, setFinalizeInvoiceId] = useState<string | null>(null);
  const [shipmentPlanOpen, setShipmentPlanOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'deliveries', id],
    queryFn: () => deliveriesApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  const { data: proofOptions, isError: proofOptionsError, refetch: refetchProofOptions } = useQuery({
    queryKey: ['supply-chain', 'deliveries', 'proof-options'],
    queryFn: deliveryProofsApi.options,
    staleTime: 5 * 60_000,
  });
  const { data: deliveryOptions, isError: deliveryOptionsError, refetch: refetchDeliveryOptions } = useQuery({
    queryKey: ['supply-chain', 'deliveries', 'options'],
    queryFn: deliveriesApi.options,
    staleTime: 5 * 60_000,
  });
  const canEdit = can('supply_chain.deliveries.create');
  const proofTypeLabels = new Map(
    (proofOptions?.proof_types ?? []).map((option) => [option.value, option.label]),
  );

  // Series C — Task C4. Real-time chain progress.
  useChainProgress('delivery', id, ['supply-chain', 'deliveries', id]);

  const advance = useMutation({
    mutationFn: (next: DeliveryStatus) => deliveriesApi.updateStatus(id, next),
    onSuccess: (_delivery, next) => {
      toast.success(next === 'cancelled' ? 'Delivery cancelled' : 'Status updated');
      setCancelDeliveryOpen(false);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const removeProof = useMutation({
    mutationFn: (proofId: string) => deliveryProofsApi.destroy(id, proofId),
    onSuccess: () => {
      toast.success('Proof archived');
      setDeleteProofId(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to archive proof'),
  });

  const restoreProof = useMutation({
    mutationFn: (proofId: string) => deliveryProofsApi.restore(id, proofId),
    onSuccess: () => {
      toast.success('Proof restored');
      setRestoreProofId(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to restore proof'),
  });

  const confirm = useMutation({
    mutationFn: () =>
      deliveriesApi.confirm(id, {
        receiver_name: receiverName.trim() || undefined,
        receiver_position: receiverPosition.trim() || undefined,
        delivery_remarks: confirmRemarks.trim() || undefined,
      }),
    onSuccess: (d) => {
      toast.success(
        d.invoice
          ? `Confirmed; draft invoice ${d.invoice.invoice_number} created`
          : d.invoice_handoff?.status === 'manual_required'
            ? 'Confirmed; Finance action is required for the customer invoice'
            : 'Delivery confirmed',
      );
      setConfirmModalOpen(false);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to confirm'),
  });

  const finalizeInvoice = useMutation({
    mutationFn: (invoiceId: string) => invoicesApi.finalize(invoiceId),
    onSuccess: () => {
      toast.success('Draft invoice finalized to AR + GL.');
      setFinalizeInvoiceId(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to finalize invoice.'),
  });

  const retryCoc = useMutation({
    mutationFn: () => deliveriesApi.retryCoc(id),
    onSuccess: () => {
      toast.success('Certificate of Conformance handoff retried.');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to retry CoC handoff.'),
  });

  const retryInvoice = useMutation({
    mutationFn: () => deliveriesApi.retryInvoice(id),
    onSuccess: () => {
      toast.success('Customer invoice handoff retried.');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to retry invoice handoff.'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load delivery"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  const statusOptions = new Map(
    (deliveryOptions?.statuses ?? []).map((option) => [option.value, option]),
  );
  const loadingPhotos = (data.proofs ?? []).filter((proof) => proof.proof_type === 'loading_photo' && !proof.deleted_at);
  const proofs = (data.proofs ?? []).filter((proof) => proof.proof_type !== 'loading_photo');
  const activeProofs = proofs.filter((proof) => !proof.deleted_at && ['photo', 'signed_dr', 'customer_po_confirmation'].includes(proof.proof_type));
  const hasProof = activeProofs.length > 0;
  const hasAnyProof = proofs.length > 0;
  const canConfirm = data.status === 'delivered' && data.can_confirm !== false && !data.billing_hold && can('supply_chain.deliveries.confirm');
  const assigned = Boolean(data.vehicle && data.driver);
  const stockReady = data.stock_reservation_status === 'reserved';
  const nextInstruction = data.status === 'scheduled'
    ? !assigned ? 'Arrange the shipment: choose dates, carrier, driver and vehicle, then photograph the loaded goods before dispatch.'
      : !stockReady ? 'Stock reservation needs attention. Check the approved lots below.'
        : 'Review the shipment plan and attach a loading photo before dispatch.'
    : data.status === 'loading' ? 'Review the shipment plan and attach a loading photo before dispatch.'
      : data.status === 'in_transit' ? 'Assigned driver uploads customer receipt after arrival.'
        : data.status === 'delivered' ? hasProof ? 'Proof uploaded. Confirm customer receipt to create the invoice.' : 'Assigned driver uploads customer receipt after arrival.'
          : data.status === 'confirmed' ? 'Customer receipt confirmed. Review the linked invoice.' : null;
  const quantityByUnit = (data.items ?? []).reduce<Record<string, number>>((totals, line) => {
    const unit = line.unit_of_measure ?? 'pcs';
    totals[unit] = (totals[unit] ?? 0) + Number(line.quantity);
    return totals;
  }, {});
  const shipmentQuantity = Object.entries(quantityByUnit).map(([unit, quantity]) => `${quantity.toLocaleString()} ${unit}`).join(' · ');

  return (
    <div>
      <PageHeader
        title={
          <span>
            {data.delivery_number}
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">
              {statusOptions.get(data.status)?.label ?? data.status.replace('_', ' ')}
            </Chip>
            {hasProof && (
              <Chip variant="success" className="ml-2">
                <LuShieldCheck size={12} className="mr-0.5" />
                {activeProofs.length} {activeProofs.length === 1 ? 'receipt' : 'receipts'}
              </Chip>
            )}
          </span>
        }
        subtitle={data.sales_order ? data.sales_order.so_number : undefined}
        backTo="/supply-chain/deliveries"
        backLabel="Deliveries"
        actions={
          <div className="flex flex-wrap items-center justify-end gap-2">
            {/* Chain 1 — the signed-paper counterpart of the driver's phone. */}
            <Button
              variant="secondary"
              size="sm"
              icon={<LuFileDown size={14} />}
              onClick={() =>
                void downloadAuthenticatedFile(
                  `/api/v1/supply-chain/deliveries/${data.id}/delivery-receipt`,
                  { openInNewTab: true, errorMessage: 'Failed to open the delivery receipt.' },
                )
              }
            >
              Delivery receipt
            </Button>
            {data.can_cancel && canEdit && (
              <Button
                variant="danger"
                size="sm"
                className="min-h-[44px] sm:min-h-0"
                loading={advance.isPending}
                onClick={() => setCancelDeliveryOpen(true)}
              >
                Cancel delivery
              </Button>
            )}
            {['scheduled', 'loading'].includes(data.status) && canEdit && (
              <Button variant="primary" size="sm" onClick={() => setShipmentPlanOpen(true)}>
                Arrange shipment
              </Button>
            )}
            {canConfirm && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuCheck size={14} />}
                disabled={!hasProof}
                title={
                  hasProof
                    ? undefined
                    : 'At least one proof of delivery is required before confirming'
                }
                onClick={() => {
                  setReceiverName(data.receiver_name ?? '');
                  setReceiverPosition(data.receiver_position ?? '');
                  setConfirmRemarks(data.delivery_remarks ?? '');
                  setConfirmModalOpen(true);
                }}
              >
                Confirm
              </Button>
            )}
          </div>
        }
      />
      {deliveryOptionsError && <div role="alert" className="px-5 py-3 text-sm text-danger-fg">
        Could not load delivery workflow options. <Button variant="secondary" size="sm" onClick={() => refetchDeliveryOptions()}>Retry delivery options</Button>
      </div>}
      {proofOptionsError && canEdit && ['in_transit', 'delivered', 'confirmed'].includes(data.status) && <div role="alert" className="px-5 py-3 text-sm text-danger-fg">
        Could not load delivery proof types. <Button variant="secondary" size="sm" onClick={() => refetchProofOptions()}>Retry proof types</Button>
      </div>}
      <div className="px-5 py-3 border-b border-default">
        <ChainHeader showStatusMessages={false} steps={buildDeliveryChain(data)} />
      </div>
      {nextInstruction && <div className="px-5 pt-5"><div className="rounded-lg border border-default bg-subtle px-4 py-3 text-sm"><span className="font-medium">Next: </span>{nextInstruction}</div></div>}
      <div className="p-5 grid grid-cols-1 lg:grid-cols-3 gap-5 min-w-0">
        <div className="lg:col-span-2 space-y-5 min-w-0">
          <DeliveryAttemptPanel key={data.id} deliveryId={data.id} lines={data.items ?? []}
            can_report_attempt_outcome={canEdit && data.can_report_attempt_outcome}
            attempt_outcome_reasons={data.attempt_outcome_reasons} attempt_outcome={data.attempt_outcome}
            report={(payload) => deliveriesApi.reportAttempt(data.id, payload)} amend={canEdit ? (payload) => deliveriesApi.amendAttempt(data.id, payload) : undefined} canOpenReturn={can('return_management.view')} />
          {data.attempt_outcome && <>
            <TruckReturnReceiptPanel key={`receipt-${data.id}`} deliveryId={data.id}
              lines={data.items ?? []} outcome={data.attempt_outcome}
              canReceive={can('return_management.receive') && data.can_receive_truck_return === true} />
            {data.attempt_outcome.reconciled_at && <TruckReturnReceiptPanel key={`late-${data.id}`} deliveryId={data.id} late
              lines={data.items ?? []} outcome={data.attempt_outcome}
              canReceive={can('return_management.receive') && data.attempt_outcome.lines.some((line) => Number(line.unaccounted_quantity) > 0)} />}
          </>}
          <Panel title="Deliver to">
            <ShipToAddress
              heading={data.sales_order?.customer?.name ?? undefined}
              address={data.delivery_address}
              contactPerson={data.delivery_contact_person}
              contactPhone={data.delivery_contact_phone}
              emptyHint="No delivery address on file for this customer. Add it on the customer record."
            />
          </Panel>
          <Panel title="Shipment details">
            <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Shipment date</dt>
                <dd className="font-mono tabular-nums">{formatDate(data.scheduled_date)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Expected arrival</dt>
                <dd className="font-mono tabular-nums">{formatDate(data.expected_delivery_date)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Carrier</dt>
                <dd>{data.carrier ?? 'Not arranged'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Departed</dt>
                <dd className="font-mono tabular-nums">{formatDateTime(data.departed_at)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Delivered</dt>
                <dd className="font-mono tabular-nums">{formatDateTime(data.delivered_at)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Vehicle</dt>
                <dd>
                  {data.vehicle ? `${data.vehicle.name} (${data.vehicle.plate_number})` : 'Not assigned'}
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Driver</dt>
                <dd>{data.driver?.name ?? 'Not assigned'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Confirmed</dt>
                <dd className="font-mono tabular-nums">{formatDateTime(data.confirmed_at)}</dd>
              </div>
            </dl>
          </Panel>
          <Panel
            title={<span className="flex flex-wrap items-baseline gap-x-3 gap-y-1">Shipment items<span className="font-mono tabular-nums text-muted">{shipmentQuantity}</span></span>}
            noPadding
          >
            {!data.items?.length ? (
              <div className="px-4 py-3 text-xs text-muted">No items.</div>
            ) : (
              <div className="overflow-x-auto"><table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Product / QC reference</Th>
                    <Th align="right">Quantity</Th>
                    {data.attempt_outcome && <Th align="right">Customer received</Th>}
                    <Th align="right">Unit price</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((i) => (
                    <tr
                      key={i.id}
                      className={trCls}
                    >
                      <Td>
                        {i.product && <div className="mb-1"><span className="font-medium">{i.product.part_number}</span><span className="block text-xs text-muted">{i.product.name}</span></div>}
                        {i.inspection ? (
                          can('quality.inspections.view') ? <Link className="font-mono text-accent hover:underline" to={`/quality/inspections/${i.inspection.id}`}>{i.inspection.inspection_number}</Link>
                          : <span className="font-mono">{i.inspection.inspection_number}</span>
                        ) : (
                          <span className="text-muted">—</span>
                        )}
                        {i.stock_movements?.map((movement) => (
                          <div key={movement.id} className="text-xs text-muted mt-1 font-mono break-words">
                            {movement.quantity} · {movement.lot_number} · {movement.from_location}
                          </div>
                        ))}
                      </Td>
                      <Td align="right" mono>
                        {Number(i.quantity).toLocaleString()}
                      </Td>
                      {data.attempt_outcome && <Td align="right" mono>{i.customer_received_quantity ?? '—'}</Td>}
                      <Td align="right" mono>
                        {i.unit_price}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
            )}
          </Panel>
          <DeliveryStockCostPanel key={`stock-cost-${data.id}`} delivery={data} />

          {!!data.preparation?.length && <Panel title="Warehouse preparation">
            <p className="text-xs text-muted mb-3">Use these approved lots when preparing the load. Availability is checked again when the truck leaves.</p>
            <ul className="divide-y divide-default">
              {data.preparation.map((line) => <li key={line.delivery_item_id} className="py-2">
                <p className="text-sm font-mono break-words">{line.product} · {line.quantity} · {line.lot_number ?? 'Lot unavailable'}</p>
                {line.locations.map((location) => <p key={location.code} className="text-xs text-muted mt-1 font-mono">{location.code}: {location.quantity}</p>)}
                {line.message && <p className="text-sm text-danger-fg mt-1">{line.message}</p>}
              </li>)}
            </ul>
          </Panel>}

          {(loadingPhotos.length > 0 || ['in_transit', 'delivered', 'confirmed'].includes(data.status)) && <Panel title="Loading evidence">
            {loadingPhotos.length > 0 ? <ul className="space-y-3">{loadingPhotos.map((photo) => <li key={photo.id}>
              <button
                type="button"
                disabled={!photo.view_url}
                aria-label={`Open loading photo ${photo.file_name}`}
                onClick={() => photo.view_url && void downloadAuthenticatedFile(photo.view_url, { openInNewTab: true, errorMessage: 'Failed to open the loading photo.' })}
                className={cn('block w-full rounded-md bg-subtle', focusRingInset)}
              >
                {photo.view_url && <img src={photo.view_url} alt="Loaded goods before departure" loading="lazy" decoding="async" className="max-h-64 w-full object-contain" />}
              </button>
              <p className="mt-1 text-xs text-muted break-words">{photo.file_name} · {photo.uploader?.name ?? '—'} · {formatDateTime(photo.uploaded_at)}</p>
            </li>)}</ul> : <p className="text-sm text-muted">No loading photo was recorded before departure.</p>}
          </Panel>}

          {/* ADV7 — Proof of Delivery. Required before confirmation. */}
          {(['in_transit', 'delivered', 'confirmed'].includes(data.status) || hasAnyProof) && <Panel
            title={
              <span className="inline-flex items-center gap-1.5">
                <LuShieldCheck
                  size={14}
                  className={hasProof ? 'text-success-fg' : 'text-warning-fg'}
                />
                Customer receipt
              </span>
            }
            meta={
              hasProof
              ? `${activeProofs.length} file${activeProofs.length === 1 ? '' : 's'}`
                : 'Required before confirmation'
            }
          >
            {(data.receiver_name || data.receiver_position || data.received_at) && (
              <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-2 text-sm mb-3 pb-3 border-b border-subtle">
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted">Received by</dt>
                  <dd>{data.receiver_name ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted">Position</dt>
                  <dd>{data.receiver_position ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted">Received at</dt>
                  <dd className="font-mono tabular-nums">{formatDateTime(data.received_at)}</dd>
                </div>
                {data.delivery_remarks && (
                  <div className="sm:col-span-2 lg:col-span-3">
                    <dt className="text-2xs uppercase tracking-wider text-muted">Remarks</dt>
                    <dd className="text-sm whitespace-pre-line">{data.delivery_remarks}</dd>
                  </div>
                )}
              </dl>
            )}

            {!hasProof && (
              <div className="text-xs text-muted mb-3 px-3 py-2 bg-subtle rounded-md border border-warning/30 flex items-start gap-2">
                <LuTriangleAlert
                  size={14}
                  className="mt-0.5 shrink-0 text-warning-fg"
                  aria-hidden="true"
                />
                <span>
                  Assigned driver uploads customer receipt after arrival.
                </span>
              </div>
            )}

            {/* Proof gallery */}
            {hasAnyProof && (
              <ul className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                {proofs.map((p) => (
                  <li
                    key={p.id}
                    className="border border-subtle rounded-md overflow-hidden bg-canvas"
                  >
                    {p.is_image && p.view_url ? (
                      <button
                        type="button"
                        onClick={() =>
                          void downloadAuthenticatedFile(p.view_url!, {
                            openInNewTab: true,
                            errorMessage: 'Failed to open the delivery proof.',
                          })
                        }
                        className={cn(
                          'block w-full aspect-video bg-subtle cursor-pointer',
                          focusRingInset,
                        )}
                      >
                        <img
                          src={p.view_url}
                          alt={p.file_name}
                          loading="lazy"
                          decoding="async"
                          className="w-full h-full object-cover"
                        />
                      </button>
                    ) : (
                      <button
                        type="button"
                        disabled={!p.view_url}
                        onClick={() =>
                          p.view_url &&
                          void downloadAuthenticatedFile(p.view_url, {
                            openInNewTab: true,
                            errorMessage: 'Failed to open the delivery proof.',
                          })
                        }
                        className={cn(
                          'flex w-full items-center justify-center aspect-video bg-subtle text-muted hover:text-accent cursor-pointer',
                          focusRingInset,
                        )}
                      >
                        <LuFileText size={32} />
                      </button>
                    )}
                    <div className="px-2.5 py-2 text-xs">
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-medium truncate">{p.file_name}</span>
                        <Chip variant="neutral">
                          {proofTypeLabels.get(p.proof_type) ?? p.proof_type}
                        </Chip>
                        {p.deleted_at && <Chip variant="warning">Archived</Chip>}
                      </div>
                      <div className="text-muted mt-0.5">
                        {p.uploader?.name ?? '—'} · {formatDateTime(p.uploaded_at)}
                      </div>
                      {p.notes && <div className="mt-1 text-muted line-clamp-2">{p.notes}</div>}
                      {canEdit && (
                        <div className="mt-1.5 flex items-center gap-2">
                          {p.deleted_at ? (
                            <LinkButton
                              onClick={() => setRestoreProofId(p.id)}
                              icon={<LuArchiveRestore size={12} />}
                              className="text-2xs"
                            >
                              Restore
                            </LinkButton>
                          ) : p.can_archive ? (
                            <LinkButton
                              tone="danger"
                              onClick={() => setDeleteProofId(p.id)}
                              icon={<LuTrash2 size={12} />}
                              className="text-2xs"
                            >
                              Archive
                            </LinkButton>
                          ) : (
                            <span className="min-w-0 break-words text-2xs text-muted leading-snug">
                              {p.archive_block_reason ?? 'This proof cannot be archived.'}
                            </span>
                          )}
                        </div>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}

          </Panel>}

          {data.billing_hold && <Panel title="Receipt and billing on hold">
            <p className="text-sm">{data.billing_hold.message}</p>
            {can('return_management.view') ? <Link className="text-accent hover:underline font-mono" to={`/return-management/cases/${data.billing_hold.case_id}`}>{data.billing_hold.case_number}</Link>
              : <p className="font-mono text-sm mt-1">{data.billing_hold.case_number}</p>}
          </Panel>}



        </div>

        <div className="space-y-5 min-w-0">
          {data.receipt_photo_url && (
            <Panel title="Customer receipt photo">
              <button
                type="button"
                onClick={() =>
                  void downloadAuthenticatedFile(data.receipt_photo_url!, {
                    openInNewTab: true,
                    errorMessage: 'Failed to open the receipt photo.',
                  })
                }
                className={cn('block w-full cursor-pointer', focusRingInset)}
              >
                <img
                  src={data.receipt_photo_url}
                  alt="Receipt"
                  className="w-full rounded-md border border-default"
                />
              </button>
            </Panel>
          )}
          {/* 2026-08-08 — auto-invoice chain: a confirmed delivery stages a draft AR
  invoice; review and finalize it here (mirrors the P2P auto-bill banner). */}
          {data.invoice && data.invoice.status === 'draft' && (
            <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
              <LuFileText size={16} className="shrink-0 text-success-fg" />
              <div className="flex-1">
                <div className="font-medium">Customer invoice auto-created</div>
                <div className="text-muted">
                  A draft AR invoice was staged from this confirmed delivery —{' '}
                  <Link
                    to={`/accounting/invoices/${data.invoice.id}`}
                    className="font-mono text-accent hover:underline"
                  >
                    {data.invoice.invoice_number ?? '(draft)'}
                  </Link>{' '}
                  · {data.invoice.total_amount} · {data.invoice.status_label ?? data.invoice.status}
                  . Review and finalize to post the receivable.
                </div>
              </div>
              {can('accounting.invoices.create') && (
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuCheck size={14} />}
                  onClick={() => setFinalizeInvoiceId(data.invoice!.id)}
                  loading={finalizeInvoice.isPending}
                >
                  Finalize
                </Button>
              )}
              {data.invoice_handoff?.status === 'manual_required' && !data.invoice && (
                <div
                  className="flex items-start gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm"
                  role="alert"
                >
                  <LuTriangleAlert size={16} className="mt-0.5 shrink-0 text-warning-fg" />
                  <div className="flex-1">
                    <div className="font-medium">Customer invoice needs Finance action</div>
                    <div className="text-muted">
                      Delivery confirmation succeeded, but the draft AR invoice was not staged. Fix
                      the accounting setup and replay the invoice handoff from Chain recovery, or
                      create the invoice manually.
                    </div>
                  </div>
                  {(can('supply_chain.deliveries.confirm') ||
                    can('accounting.invoices.create')) && (
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => retryInvoice.mutate()}
                      loading={retryInvoice.isPending}
                    >
                      Retry Invoice
                    </Button>
                  )}
                </div>
              )}
            </div>
          )}
          {data.invoice && (
            <Panel title="Invoice">
              <dl className="text-sm space-y-2">
                <div className="flex justify-between">
                  <dt className="text-muted">Number</dt>
                  <dd className="font-mono">{data.invoice.invoice_number}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Total</dt>
                  <dd className="font-mono tabular-nums">{data.invoice.total_amount}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Status</dt>
                  <dd>
                    <Chip variant="neutral">{data.invoice.status}</Chip>
                  </dd>
                </div>
              </dl>
            </Panel>
          )}
          {data.coc_handoff?.status === 'manual_required' && (
            <div
              className="flex items-start gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm"
              role="alert"
            >
              <LuTriangleAlert size={16} className="mt-0.5 shrink-0 text-warning-fg" />
              <div className="flex-1">
                <div className="font-medium">Certificate of Conformance needs Quality action</div>
                <div className="text-muted">
                  {data.coc_handoff.message ??
                    'The certificate could not be attached automatically.'}
                </div>
              </div>
              {can('supply_chain.deliveries.confirm') && (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => retryCoc.mutate()}
                  loading={retryCoc.isPending}
                >
                  Retry CoC
                </Button>
              )}
            </div>
          )}
          {data.notes && (
            <Panel title="Notes">
              <p className="whitespace-pre-line text-sm">{data.notes}</p>
            </Panel>
          )}
          {/* ADV3 — IATF 16949 outgoing shipment lot. */}
          {data.shipment_lot && (
            <Panel
              title={
                <span className="inline-flex items-center gap-1.5">
                  <LuTag size={14} className="text-accent" />
                  Shipment lot
                </span>
              }
            >
              <dl className="text-sm space-y-2">
                <div className="flex justify-between">
                  <dt className="text-muted">Lot</dt>
                  <dd className="font-mono">
                    <Link
                      to={`/quality/traceability?term=${encodeURIComponent(data.shipment_lot.lot_number)}`}
                      className="text-accent hover:underline"
                    >
                      {data.shipment_lot.lot_number}
                    </Link>
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Lot date</dt>
                  <dd className="font-mono tabular-nums">{data.shipment_lot.lot_date ?? '—'}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Quantity</dt>
                  <dd className="font-mono tabular-nums">{data.shipment_lot.quantity}</dd>
                </div>
                {data.shipment_lot.product && (
                  <div className="flex justify-between">
                    <dt className="text-muted">Product</dt>
                    <dd className="text-right">
                      {data.shipment_lot.product.part_number ? (
                        <span className="font-mono">{data.shipment_lot.product.part_number}</span>
                      ) : null}
                      {data.shipment_lot.product.name && (
                        <span className="text-muted ml-1">{data.shipment_lot.product.name}</span>
                      )}
                    </dd>
                  </div>
                )}
                <div className="flex justify-between">
                  <dt className="text-muted">Batches</dt>
                  <dd className="font-mono tabular-nums">{data.shipment_lot.work_order_count}</dd>
                </div>
              </dl>
            </Panel>
          )}

          {/* Sprint 7 audit fix: LinkedRecords (O2C chain) */}
          <Panel title="Linked records">
            <LinkedRecords
              groups={[
                ...(data.sales_order
                  ? [
                      {
                        label: 'Sales order',
                        items: [
                          {
                            id: data.sales_order.so_number,
                            href: `/crm/sales-orders/${data.sales_order.id}`,
                          },
                        ],
                      },
                    ]
                  : []),
                ...(data.invoice
                  ? [
                      {
                        label: 'Invoice',
                        items: [
                          {
                            id: data.invoice.invoice_number,
                            href: `/accounting/invoices/${data.invoice.id}`,
                            meta: `${data.invoice.total_amount} · ${data.invoice.status}`,
                          },
                        ],
                      },
                    ]
                  : []),
                ...(data.items?.length
                  ? [
                      {
                        label: 'Inspections',
                        items: data.items
                          .filter((i) => i.inspection)
                          .map((i) => ({
                            id: i.inspection!.inspection_number,
                            href: `/quality/inspections/${i.inspection!.id}`,
                            meta: i.inspection!.status,
                          })),
                      },
                    ]
                  : []),
              ]}
            />
          </Panel>

        </div>
      </div>
      {/* Confirm modal — captures receiver details. */}
      <Modal
        isOpen={confirmModalOpen}
        onClose={() => setConfirmModalOpen(false)}
        title="Confirm delivery"
      >
        <div className="space-y-3">
          <p className="text-sm text-muted">
            {hasProof
              ? `${activeProofs.length} proof file${activeProofs.length === 1 ? '' : 's'} attached. Capture the receiver's details to finalize the delivery and attempt to create a draft invoice.`
              : 'At least one proof must be uploaded first.'}
          </p>
          <Input
            label="Received by"
            type="text"
            value={receiverName}
            onChange={(e) => setReceiverName(e.target.value)}
            placeholder="e.g. Maria Santos"
          />
          <Input
            label="Position"
            type="text"
            value={receiverPosition}
            onChange={(e) => setReceiverPosition(e.target.value)}
            placeholder="e.g. Purchasing Officer"
          />
          <Textarea
            label="Remarks (optional)"
            value={confirmRemarks}
            onChange={(e) => setConfirmRemarks(e.target.value)}
            rows={3}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setConfirmModalOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              icon={<LuCheck size={14} />}
              loading={confirm.isPending}
              disabled={!hasProof}
              onClick={() => confirm.mutate()}
            >
              Confirm delivery
            </Button>
          </div>
        </div>
      </Modal>{' '}
      {/* 2026-08-08 — finalize the auto-created draft invoice from the delivery. */}
      {shipmentPlanOpen && ['scheduled', 'loading'].includes(data.status) && canEdit && <ShipmentPlanDialog
        key={data.id}
        delivery={data}
        onClose={() => setShipmentPlanOpen(false)}
      />}
      {/* 2026-08-08 — finalize the auto-created draft invoice from the delivery. */}
      <ConfirmDialog
        isOpen={!!finalizeInvoiceId}
        onClose={() => setFinalizeInvoiceId(null)}
        onConfirm={() => {
          if (finalizeInvoiceId) finalizeInvoice.mutate(finalizeInvoiceId);
        }}
        title="Finalize draft invoice?"
        description="Finalizing locks the invoice number, posts the AR/revenue journal entry, and flips the invoice to Finalized (the SO is promoted to Invoiced). Review the auto-created amounts before posting."
        confirmLabel="Finalize invoice"
        pending={finalizeInvoice.isPending}
      />
      <ConfirmDialog
        isOpen={cancelDeliveryOpen}
        onClose={() => setCancelDeliveryOpen(false)}
        onConfirm={() => advance.mutate('cancelled')}
        title="Cancel this delivery?"
        description={`Delivery ${data.delivery_number} cannot be dispatched after cancellation. Any reserved stock will be released; create a new delivery if these items still need to ship.`}
        confirmLabel="Cancel delivery"
        variant="danger"
        pending={advance.isPending}
      />
      {/* Archive proof confirmation */}
      <ConfirmDialog
        isOpen={!!deleteProofId}
        onClose={() => setDeleteProofId(null)}
        onConfirm={() => {
          if (deleteProofId) removeProof.mutate(deleteProofId);
        }}
        title="Archive this proof?"
        description="The file will be archived and can be restored later."
        confirmLabel="Archive"
        variant="danger"
        pending={removeProof.isPending}
      />
      {/* Restore proof confirmation */}
      <ConfirmDialog
        isOpen={!!restoreProofId}
        onClose={() => setRestoreProofId(null)}
        onConfirm={() => {
          if (restoreProofId) restoreProof.mutate(restoreProofId);
        }}
        title="Restore this proof?"
        description="The file will be restored and count toward the delivery proof requirement."
        confirmLabel="Restore"
        pending={restoreProof.isPending}
      />
    </div>
  );
}
