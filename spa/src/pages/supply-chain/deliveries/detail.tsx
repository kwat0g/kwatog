/** Sprint 7 / ADV7 — Delivery detail with Proof-of-Delivery management. */
import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { Truck, Camera, Check, ArrowRight, Tag, Trash2, FileText, Image as ImageIcon, ShieldCheck } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { deliveriesApi, deliveryProofsApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { buildDeliveryO2cChain } from '@/lib/chains';
import { usePermission } from '@/hooks/usePermission';
import { useChainProgress } from '@/hooks/useChainProgress';
import type { DeliveryStatus, DeliveryProofType } from '@/types/supplyChain';

const STATUS_CHIP: Record<DeliveryStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  scheduled: 'neutral', loading: 'info', in_transit: 'info',
  delivered: 'warning', confirmed: 'success', cancelled: 'neutral',
};

const NEXT: Record<DeliveryStatus, DeliveryStatus | null> = {
  scheduled: 'loading',
  loading: 'in_transit',
  in_transit: 'delivered',
  delivered: 'confirmed',
  confirmed: null,
  cancelled: null,
};

const PROOF_TYPE_LABEL: Record<DeliveryProofType, string> = {
  signed_dr: 'Signed DR',
  photo: 'Photo',
  customer_po_confirmation: 'Customer PO confirmation',
  other: 'Other',
};

export default function DeliveryDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const fileInput = useRef<HTMLInputElement | null>(null);

  // Proof upload form state.
  const [proofType, setProofType] = useState<DeliveryProofType>('signed_dr');
  const [proofNotes, setProofNotes] = useState('');
  const [confirmModalOpen, setConfirmModalOpen] = useState(false);
  const [receiverName, setReceiverName] = useState('');
  const [receiverPosition, setReceiverPosition] = useState('');
  const [confirmRemarks, setConfirmRemarks] = useState('');
  const [deleteProofId, setDeleteProofId] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'deliveries', id],
    queryFn: () => deliveriesApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  // Series C — Task C4. Real-time chain progress.
  useChainProgress('delivery', id, ['supply-chain', 'deliveries', id]);

  const advance = useMutation({
    mutationFn: (next: DeliveryStatus) => deliveriesApi.updateStatus(id, next),
    onSuccess: () => {
      toast.success('Status updated');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const upload = useMutation({
    mutationFn: (file: File) => deliveriesApi.uploadReceipt(id, file),
    onSuccess: () => {
      toast.success('Receipt photo uploaded');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Upload failed'),
  });

  const uploadProof = useMutation({
    mutationFn: (file: File) => deliveryProofsApi.upload(id, file, proofType, proofNotes || undefined),
    onSuccess: () => {
      toast.success('Proof uploaded');
      setProofNotes('');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Proof upload failed'),
  });

  const removeProof = useMutation({
    mutationFn: (proofId: string) => deliveryProofsApi.destroy(id, proofId),
    onSuccess: () => {
      toast.success('Proof removed');
      setDeleteProofId(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to remove proof'),
  });

  const confirm = useMutation({
    mutationFn: () => deliveriesApi.confirm(id, {
      receiver_name: receiverName.trim() || undefined,
      receiver_position: receiverPosition.trim() || undefined,
      delivery_remarks: confirmRemarks.trim() || undefined,
    }),
    onSuccess: (d) => {
      toast.success(d.invoice ? `Confirmed; draft invoice ${d.invoice.invoice_number} created` : 'Delivery confirmed');
      setConfirmModalOpen(false);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to confirm'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return <EmptyState icon="alert-circle" title="Failed to load delivery"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }

  const next = NEXT[data.status];
  const proofs = data.proofs ?? [];
  const hasProof = proofs.length > 0;
  const canConfirm = data.status === 'delivered' && can('supply_chain.deliveries.confirm');
  const canEdit = can('supply_chain.deliveries.create');
  const canUploadProofNow = ['in_transit', 'delivered', 'confirmed'].includes(data.status) && canEdit;

  return (
    <div>
      <PageHeader
        title={
          <span>
            {data.delivery_number}
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">{data.status.replace('_', ' ')}</Chip>
            {hasProof && (
              <Chip variant="success" className="ml-2">
                <ShieldCheck size={12} className="mr-0.5" />
                {proofs.length} {proofs.length === 1 ? 'proof' : 'proofs'}
              </Chip>
            )}
          </span>
        }
        subtitle={data.sales_order ? `SO ${data.sales_order.so_number}` : undefined}
        backTo="/supply-chain/deliveries"
        backLabel="Deliveries"
        breadcrumbs={[{ label: 'Deliveries', href: '/supply-chain/deliveries' }, { label: data.delivery_number }]}
        actions={
          <div className="flex items-center gap-2">
            {next && data.status !== 'delivered' && canEdit && (
              <Button variant="secondary" size="sm" icon={<ArrowRight size={14} />}
                loading={advance.isPending} onClick={() => advance.mutate(next)}>
                Mark {next.replace('_', ' ')}
              </Button>
            )}
            {data.status === 'in_transit' && canEdit && (
              <Button variant="secondary" size="sm" icon={<Truck size={14} />}
                loading={advance.isPending} onClick={() => advance.mutate('delivered')}>
                Mark delivered
              </Button>
            )}
            {data.status === 'delivered' && canEdit && (
              <>
                <input
                  ref={fileInput}
                  type="file"
                  accept="image/*"
                  hidden
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) upload.mutate(file);
                    e.target.value = '';
                  }}
                />
                <Button variant="secondary" size="sm" icon={<Camera size={14} />}
                  loading={upload.isPending} onClick={() => fileInput.current?.click()}>
                  {data.receipt_photo_url ? 'Replace receipt' : 'Quick photo'}
                </Button>
              </>
            )}
            {canConfirm && (
              <Button
                variant="primary"
                size="sm"
                icon={<Check size={14} />}
                disabled={!hasProof}
                title={hasProof ? undefined : 'At least one proof of delivery is required before confirming'}
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

      {/* P1 — Order-to-Cash chain anchored on the Delivery record. */}
      <div className="px-5 py-3 border-b border-default">
        <ChainHeader steps={buildDeliveryO2cChain(data)} />
      </div>

      <div className="px-5 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Schedule">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Scheduled</dt>
                <dd className="font-mono tabular-nums">{data.scheduled_date ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Departed</dt>
                <dd className="font-mono tabular-nums">{data.departed_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Delivered</dt>
                <dd className="font-mono tabular-nums">{data.delivered_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Vehicle</dt>
                <dd>{data.vehicle ? `${data.vehicle.name} (${data.vehicle.plate_number})` : '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Driver</dt>
                <dd>{data.driver?.name ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Confirmed</dt>
                <dd className="font-mono tabular-nums">{data.confirmed_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
            </dl>
          </Panel>

          {/* ADV7 — Proof of Delivery. Required before confirmation. */}
          <Panel
            title={
              <span className="inline-flex items-center gap-1.5">
                <ShieldCheck size={14} className={hasProof ? 'text-success' : 'text-warning'} />
                Proof of delivery
              </span>
            }
            meta={hasProof ? `${proofs.length} file${proofs.length === 1 ? '' : 's'}` : 'Required before confirmation'}
          >
            {(data.receiver_name || data.receiver_position || data.received_at) && (
              <dl className="grid grid-cols-3 gap-x-4 gap-y-2 text-sm mb-3 pb-3 border-b border-subtle">
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
                  <dd className="font-mono tabular-nums">{data.received_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
                </div>
                {data.delivery_remarks && (
                  <div className="col-span-3">
                    <dt className="text-2xs uppercase tracking-wider text-muted">Remarks</dt>
                    <dd className="text-sm whitespace-pre-line">{data.delivery_remarks}</dd>
                  </div>
                )}
              </dl>
            )}

            {!hasProof && (
              <div className="text-xs text-muted mb-3 px-3 py-2 bg-subtle rounded-md border border-warning/30">
                ⚠ No proof uploaded yet. After delivering the goods, upload the signed delivery
                receipt or a photo here. <strong>Required</strong> before the delivery can be confirmed.
              </div>
            )}

            {/* Proof gallery */}
            {hasProof && (
              <ul className="grid grid-cols-2 gap-3 mb-4">
                {proofs.map((p) => (
                  <li key={p.id} className="border border-subtle rounded-md overflow-hidden bg-canvas">
                    {p.is_image && p.view_url ? (
                      <a href={p.view_url} target="_blank" rel="noopener noreferrer" className="block aspect-video bg-subtle">
                        <img src={p.view_url} alt={p.file_name} className="w-full h-full object-cover" />
                      </a>
                    ) : (
                      <a href={p.view_url ?? '#'} target="_blank" rel="noopener noreferrer"
                         className="flex items-center justify-center aspect-video bg-subtle text-muted hover:text-accent">
                        <FileText size={32} />
                      </a>
                    )}
                    <div className="px-2.5 py-2 text-xs">
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-medium truncate">{p.file_name}</span>
                        <Chip variant="neutral">{PROOF_TYPE_LABEL[p.proof_type]}</Chip>
                      </div>
                      <div className="text-muted mt-0.5">
                        {p.uploader?.name ?? '—'} · {p.uploaded_at?.slice(0, 16).replace('T', ' ') ?? '—'}
                      </div>
                      {p.notes && <div className="mt-1 text-muted line-clamp-2">{p.notes}</div>}
                      {canEdit && (
                        <button
                          type="button"
                          onClick={() => setDeleteProofId(p.id)}
                          className="mt-1.5 inline-flex items-center gap-1 text-2xs text-danger hover:underline"
                        >
                          <Trash2 size={12} />
                          Remove
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}

            {/* Upload form */}
            {canUploadProofNow && (
              <div className="border-t border-subtle pt-3">
                <div className="grid grid-cols-3 gap-2 mb-2">
                  <select
                    value={proofType}
                    onChange={(e) => setProofType(e.target.value as DeliveryProofType)}
                    className="text-sm rounded-md border border-default bg-canvas px-2 py-1.5"
                  >
                    <option value="signed_dr">Signed delivery receipt</option>
                    <option value="photo">Photo</option>
                    <option value="customer_po_confirmation">Customer PO confirmation</option>
                    <option value="other">Other</option>
                  </select>
                  <input
                    type="text"
                    value={proofNotes}
                    onChange={(e) => setProofNotes(e.target.value)}
                    placeholder="Notes (optional)"
                    className="col-span-2 text-sm rounded-md border border-default bg-canvas px-2 py-1.5"
                  />
                </div>
                <label className="block w-full">
                  <input
                    type="file"
                    accept="image/*,application/pdf"
                    className="hidden"
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) uploadProof.mutate(file);
                      e.target.value = '';
                    }}
                  />
                  <span className="flex items-center justify-center gap-2 cursor-pointer w-full py-3 border-2 border-dashed border-default rounded-md text-sm text-muted hover:border-accent hover:text-accent transition-colors">
                    <ImageIcon size={16} />
                    {uploadProof.isPending ? 'Uploading…' : 'Tap to upload (camera or file, max 10MB)'}
                  </span>
                </label>
              </div>
            )}
          </Panel>

          <Panel title="Items" meta={`${data.items?.length ?? 0} ${(data.items?.length ?? 0) === 1 ? 'line' : 'lines'}`} noPadding>
            {!data.items?.length ? (
              <div className="px-4 py-3 text-xs text-muted">No items.</div>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="px-2.5 py-2 text-left text-2xs uppercase tracking-wider text-muted font-medium">Inspection</th>
                    <th className="px-2.5 py-2 text-right text-2xs uppercase tracking-wider text-muted font-medium">Qty</th>
                    <th className="px-2.5 py-2 text-right text-2xs uppercase tracking-wider text-muted font-medium">Unit price</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((i) => (
                    <tr key={i.id} className="border-t border-subtle">
                      <td className="px-2.5 py-2">
                        {i.inspection ? (
                          <Link to={`/quality/inspections/${i.inspection.id}`} className="font-mono text-accent hover:underline">
                            {i.inspection.inspection_number}
                          </Link>
                        ) : <span className="text-muted">—</span>}
                      </td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{i.quantity}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{i.unit_price}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          {data.receipt_photo_url && (
            <Panel title="Quick receipt photo">
              <a href={data.receipt_photo_url} target="_blank" rel="noopener noreferrer">
                <img src={data.receipt_photo_url} alt="Receipt" className="w-full rounded-md border border-default" />
              </a>
            </Panel>
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
                  <dd><Chip variant="neutral">{data.invoice.status}</Chip></dd>
                </div>
              </dl>
            </Panel>
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
                  <Tag size={14} className="text-accent" />
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
                      {data.shipment_lot.product.part_number
                        ? <span className="font-mono">{data.shipment_lot.product.part_number}</span>
                        : null}
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
                ...(data.sales_order ? [{
                  label: 'Sales order',
                  items: [{ id: data.sales_order.so_number, href: `/crm/sales-orders/${data.sales_order.id}` }],
                }] : []),
                ...(data.invoice ? [{
                  label: 'Invoice',
                  items: [{
                    id: data.invoice.invoice_number,
                    href: `/accounting/invoices/${data.invoice.id}`,
                    meta: `${data.invoice.total_amount} · ${data.invoice.status}`,
                  }],
                }] : []),
                ...(data.items?.length
                  ? [{
                      label: 'Inspections',
                      items: data.items
                        .filter((i) => i.inspection)
                        .map((i) => ({
                          id: i.inspection!.inspection_number,
                          href: `/quality/inspections/${i.inspection!.id}`,
                          meta: i.inspection!.status,
                        })),
                    }]
                  : []),
              ]}
            />
          </Panel>
          <Panel title="Navigation">
            <Link to="/supply-chain/deliveries" className="text-xs text-accent hover:underline">← Back to deliveries</Link>
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
              ? `${proofs.length} proof file${proofs.length === 1 ? '' : 's'} attached. Capture the receiver's details to finalize the delivery and create a draft invoice.`
              : 'At least one proof must be uploaded first.'}
          </p>
          <div>
            <label className="text-2xs uppercase tracking-wider text-muted block mb-1">Received by</label>
            <input
              type="text"
              value={receiverName}
              onChange={(e) => setReceiverName(e.target.value)}
              placeholder="e.g. Maria Santos"
              className="w-full text-sm rounded-md border border-default bg-canvas px-3 py-2"
            />
          </div>
          <div>
            <label className="text-2xs uppercase tracking-wider text-muted block mb-1">Position</label>
            <input
              type="text"
              value={receiverPosition}
              onChange={(e) => setReceiverPosition(e.target.value)}
              placeholder="e.g. Purchasing Officer"
              className="w-full text-sm rounded-md border border-default bg-canvas px-3 py-2"
            />
          </div>
          <div>
            <label className="text-2xs uppercase tracking-wider text-muted block mb-1">Remarks (optional)</label>
            <textarea
              value={confirmRemarks}
              onChange={(e) => setConfirmRemarks(e.target.value)}
              rows={3}
              className="w-full text-sm rounded-md border border-default bg-canvas px-3 py-2"
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setConfirmModalOpen(false)}>Cancel</Button>
            <Button
              variant="primary"
              icon={<Check size={14} />}
              loading={confirm.isPending}
              disabled={!hasProof}
              onClick={() => confirm.mutate()}
            >
              Confirm delivery
            </Button>
          </div>
        </div>
      </Modal>

      {/* Delete proof confirmation */}
      <ConfirmDialog
        isOpen={!!deleteProofId}
        onClose={() => setDeleteProofId(null)}
        onConfirm={() => { if (deleteProofId) removeProof.mutate(deleteProofId); }}
        title="Remove this proof?"
        description="The file will be permanently deleted from storage."
        confirmLabel="Remove"
        variant="danger"
        pending={removeProof.isPending}
      />
    </div>
  );
}
