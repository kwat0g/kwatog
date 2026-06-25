/** Supply Chain — Shipment detail page with status advancement and document management. */
import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Download, FileText, Trash2, Upload } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { shipmentsApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ShipmentDocumentType, ShipmentStatus } from '@/types/supplyChain';

const STATUS_CHIP: Record<ShipmentStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  ordered: 'neutral',
  shipped: 'info',
  in_transit: 'info',
  customs: 'warning',
  cleared: 'info',
  received: 'success',
  cancelled: 'neutral',
};

const STATUS_ORDER: ShipmentStatus[] = ['ordered', 'shipped', 'in_transit', 'customs', 'cleared', 'received'];

const NEXT_STATUS: Partial<Record<ShipmentStatus, ShipmentStatus>> = {
  ordered: 'shipped',
  shipped: 'in_transit',
  in_transit: 'customs',
  customs: 'cleared',
  cleared: 'received',
};

const DOC_TYPE_LABEL: Record<ShipmentDocumentType, string> = {
  proforma_invoice: 'Proforma invoice',
  commercial_invoice: 'Commercial invoice',
  packing_list: 'Packing list',
  bill_of_lading: 'Bill of lading',
  import_entry: 'Import entry',
  certificate_of_origin: 'Certificate of origin',
  msds: 'MSDS',
  boc_release: 'BOC release',
  insurance_certificate: 'Insurance certificate',
};

function formatBytes(bytes: number | null): string {
  if (!bytes) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function ShipmentDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();

  const fileInput = useRef<HTMLInputElement | null>(null);
  const [docType, setDocType] = useState<ShipmentDocumentType>('bill_of_lading');
  const [docNotes, setDocNotes] = useState('');
  const [statusNoteOpen, setStatusNoteOpen] = useState(false);
  const [statusNote, setStatusNote] = useState('');
  const [pendingStatus, setPendingStatus] = useState<ShipmentStatus | null>(null);
  const [deleteDocId, setDeleteDocId] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'shipments', id],
    queryFn: () => shipmentsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  const advance = useMutation({
    mutationFn: ({ status, note }: { status: ShipmentStatus; note?: string }) =>
      shipmentsApi.updateStatus(id, status, note),
    onSuccess: () => {
      toast.success('Status updated');
      setStatusNoteOpen(false);
      setStatusNote('');
      setPendingStatus(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to update status'),
  });

  const uploadDoc = useMutation({
    mutationFn: (file: File) => shipmentsApi.uploadDocument(id, file, docType, docNotes || undefined),
    onSuccess: () => {
      toast.success('Document uploaded');
      setDocNotes('');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Upload failed'),
  });

  const removeDoc = useMutation({
    mutationFn: (docId: string) => shipmentsApi.destroyDocument(docId),
    onSuccess: () => {
      toast.success('Document removed');
      setDeleteDocId(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to remove document'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load shipment"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  const nextStatus = NEXT_STATUS[data.status];
  const canManage = can('supply_chain.shipments.manage');
  const documents = data.documents ?? [];
  const currentStep = STATUS_ORDER.indexOf(data.status);

  return (
    <div>
      <PageHeader
        title={
          <span>
            <span className="font-mono">{data.shipment_number}</span>
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">
              {data.status.replace('_', ' ')}
            </Chip>
          </span>
        }
        subtitle={data.purchase_order ? `PO ${data.purchase_order.po_number}` : undefined}
        backTo="/supply-chain/shipments"
        backLabel="Shipments"
        breadcrumbs={[
          { label: 'Shipments', href: '/supply-chain/shipments' },
          { label: data.shipment_number },
        ]}
        actions={
          nextStatus && canManage ? (
            <Button
              variant="primary"
              size="sm"
              icon={<ArrowRight size={14} />}
              loading={advance.isPending}
              onClick={() => {
                setPendingStatus(nextStatus);
                setStatusNoteOpen(true);
              }}
            >
              Mark {nextStatus.replace('_', ' ')}
            </Button>
          ) : undefined
        }
      />

      {/* Status timeline */}
      <div className="px-5 py-4 border-b border-default">
        <ol className="flex items-center gap-0">
          {STATUS_ORDER.map((s, i) => {
            const isDone = i < currentStep;
            const isCurrent = i === currentStep;
            const isLast = i === STATUS_ORDER.length - 1;
            return (
              <li key={s} className="flex items-center">
                <div className="flex flex-col items-center">
                  <div
                    className={[
                      'w-7 h-7 rounded-full flex items-center justify-center text-xs font-medium border-2',
                      isDone
                        ? 'bg-success border-success text-white'
                        : isCurrent
                          ? 'border-accent text-accent bg-canvas'
                          : 'border-default text-muted bg-canvas',
                    ].join(' ')}
                  >
                    {isDone ? '✓' : i + 1}
                  </div>
                  <span
                    className={[
                      'text-2xs mt-1 capitalize',
                      isCurrent ? 'text-accent font-medium' : isDone ? 'text-success' : 'text-muted',
                    ].join(' ')}
                  >
                    {s.replace('_', ' ')}
                  </span>
                </div>
                {!isLast && (
                  <div className={['h-0.5 w-10 mb-4', isDone ? 'bg-success' : 'bg-default'].join(' ')} />
                )}
              </li>
            );
          })}
        </ol>
      </div>

      <div className="px-5 pt-4 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          {/* Shipment info */}
          <Panel title="Shipment details">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Carrier</dt>
                <dd>{data.carrier ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Vessel</dt>
                <dd>{data.vessel ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Container</dt>
                <dd className="font-mono tabular-nums">{data.container_number ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">B/L number</dt>
                <dd className="font-mono tabular-nums">{data.bl_number ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">ETD</dt>
                <dd className="font-mono tabular-nums">{data.etd ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">ATD</dt>
                <dd className="font-mono tabular-nums">{data.atd ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">ETA</dt>
                <dd className="font-mono tabular-nums">{data.eta ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">ATA</dt>
                <dd className="font-mono tabular-nums">{data.ata ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Customs cleared</dt>
                <dd className="font-mono tabular-nums">{data.customs_clearance_date ?? '—'}</dd>
              </div>
            </dl>
            {data.notes && (
              <div className="mt-3 pt-3 border-t border-subtle">
                <dt className="text-2xs uppercase tracking-wider text-muted mb-1">Notes</dt>
                <dd className="text-sm whitespace-pre-line">{data.notes}</dd>
              </div>
            )}
          </Panel>

          {/* Documents */}
          <Panel
            title="Shipment documents"
            meta={documents.length > 0 ? `${documents.length} file${documents.length === 1 ? '' : 's'}` : undefined}
          >
            {documents.length === 0 && (
              <div className="text-xs text-muted mb-3 px-3 py-2 bg-subtle rounded-md">
                No documents uploaded yet. Upload import documents (B/L, commercial invoice, packing list, etc.) here.
              </div>
            )}

            {documents.length > 0 && (
              <ul className="space-y-2 mb-4">
                {documents.map((doc) => (
                  <li key={doc.id} className="flex items-center gap-3 px-3 py-2.5 border border-subtle rounded-md bg-canvas">
                    <FileText size={16} className="shrink-0 text-muted" />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium truncate">{doc.original_filename ?? 'Document'}</span>
                        <Chip variant="neutral">{DOC_TYPE_LABEL[doc.document_type] ?? doc.document_type}</Chip>
                      </div>
                      <div className="text-2xs text-muted mt-0.5">
                        {formatBytes(doc.file_size_bytes)}
                        {doc.uploader && ` · ${doc.uploader.name}`}
                        {doc.uploaded_at && ` · ${doc.uploaded_at.slice(0, 10)}`}
                      </div>
                      {doc.notes && <div className="text-xs text-muted mt-0.5">{doc.notes}</div>}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      {doc.url && (
                        <a
                          href={doc.url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-xs text-accent hover:underline"
                        >
                          Download
                        </a>
                      )}
                      {canManage && (
                        <button
                          type="button"
                          onClick={() => setDeleteDocId(doc.id)}
                          className="text-danger hover:opacity-70"
                          title="Remove document"
                        >
                          <Trash2 size={14} />
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}

            {canManage && data.status !== 'received' && data.status !== 'cancelled' && (
              <div className="border-t border-subtle pt-3">
                <div className="grid grid-cols-2 gap-2 mb-2">
                  <Select
                    value={docType}
                    onChange={(e) => setDocType(e.target.value as ShipmentDocumentType)}
                  >
                    {(Object.keys(DOC_TYPE_LABEL) as ShipmentDocumentType[]).map((t) => (
                      <option key={t} value={t}>{DOC_TYPE_LABEL[t]}</option>
                    ))}
                  </Select>
                  <Input
                    type="text"
                    value={docNotes}
                    onChange={(e) => setDocNotes(e.target.value)}
                    placeholder="Notes (optional)"
                  />
                </div>
                <label className="block w-full">
                  <input
                    ref={fileInput}
                    type="file"
                    accept="application/pdf,image/*,.xlsx,.csv"
                    className="hidden"
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) uploadDoc.mutate(file);
                      e.target.value = '';
                    }}
                  />
                  <span className="flex items-center justify-center gap-2 cursor-pointer w-full py-3 border-2 border-dashed border-default rounded-md text-sm text-muted hover:border-accent hover:text-accent transition-colors">
                    <Upload size={16} />
                    {uploadDoc.isPending ? 'Uploading…' : 'Click to upload (PDF, image, Excel — max 20 MB)'}
                  </span>
                </label>
              </div>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          {/* PO link */}
          {data.purchase_order && (
            <Panel title="Purchase order">
              <Link
                to={`/purchasing/purchase-orders/${data.purchase_order.id}`}
                className="font-mono text-accent hover:underline text-sm"
              >
                {data.purchase_order.po_number}
              </Link>
            </Panel>
          )}

          {/* ImpEx document generation */}
          <Panel title="ImpEx documents">
            <div className="space-y-2">
              <Button
                variant="secondary"
                size="sm"
                icon={<Download size={14} />}
                className="w-full justify-start"
                onClick={() =>
                  window.open(
                    `/api/v1/supply-chain/shipments/${id}/packing-list`,
                    '_blank',
                  )
                }
              >
                Packing list
              </Button>
              <Button
                variant="secondary"
                size="sm"
                icon={<Download size={14} />}
                className="w-full justify-start"
                onClick={() =>
                  window.open(
                    `/api/v1/supply-chain/shipments/${id}/commercial-invoice`,
                    '_blank',
                  )
                }
              >
                Commercial invoice
              </Button>
            </div>
            <p className="text-2xs text-muted mt-2">
              Auto-generated from shipment + PO data
            </p>
          </Panel>

          {/* Timestamps */}
          <Panel title="Record">
            <dl className="text-sm space-y-2">
              <div className="flex justify-between gap-2">
                <dt className="text-muted">Created</dt>
                <dd className="font-mono tabular-nums text-xs">{data.created_at?.slice(0, 10) ?? '—'}</dd>
              </div>
              <div className="flex justify-between gap-2">
                <dt className="text-muted">Updated</dt>
                <dd className="font-mono tabular-nums text-xs">{data.updated_at?.slice(0, 10) ?? '—'}</dd>
              </div>
            </dl>
          </Panel>

          <Panel title="Navigation">
            <Link to="/supply-chain/shipments" className="text-xs text-accent hover:underline">
              ← Back to shipments
            </Link>
          </Panel>
        </div>
      </div>

      {/* Status advance modal */}
      <Modal
        isOpen={statusNoteOpen}
        onClose={() => { setStatusNoteOpen(false); setStatusNote(''); setPendingStatus(null); }}
        title={`Mark as ${pendingStatus?.replace('_', ' ') ?? ''}`}
      >
        <div className="space-y-3">
          <div>
            <label className="text-2xs uppercase tracking-wider text-muted block mb-1">Note (optional)</label>
            <textarea
              value={statusNote}
              onChange={(e) => setStatusNote(e.target.value)}
              rows={3}
              placeholder="Add a note about this status change…"
              className="w-full text-sm rounded-md border border-default bg-canvas px-3 py-2"
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button
              variant="secondary"
              onClick={() => { setStatusNoteOpen(false); setStatusNote(''); setPendingStatus(null); }}
            >
              Cancel
            </Button>
            <Button
              variant="primary"
              icon={<ArrowRight size={14} />}
              loading={advance.isPending}
              onClick={() => {
                if (pendingStatus) {
                  advance.mutate({ status: pendingStatus, note: statusNote.trim() || undefined });
                }
              }}
            >
              Confirm
            </Button>
          </div>
        </div>
      </Modal>

      {/* Delete document confirmation */}
      <ConfirmDialog
        isOpen={!!deleteDocId}
        onClose={() => setDeleteDocId(null)}
        onConfirm={() => { if (deleteDocId) removeDoc.mutate(deleteDocId); }}
        title="Remove this document?"
        description="The file will be permanently deleted from storage."
        confirmLabel="Remove"
        variant="danger"
        pending={removeDoc.isPending}
      />
    </div>
  );
}
