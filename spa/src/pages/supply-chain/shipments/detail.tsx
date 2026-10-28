/** Supply Chain — Shipment detail page with status advancement and document management. */
import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  LuArrowRight,
  LuDownload,
  LuFileText,
  LuPencil,
  LuTrash2,
  LuUpload,
  LuArchiveRestore,
  LuCheck,
} from '@/lib/icons';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { downloadAuthenticatedFile } from '@/api/download';
import { shipmentContainersApi, shipmentsApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { LinkButton } from '@/components/ui/LinkButton';
import { usePermission } from '@/hooks/usePermission';
import type { ContainerSize, ContainerType, LandedCostAllocationMethod, ShipmentContainer, ShipmentContainerData, ShipmentDocumentType, ShipmentStatus } from '@/types/supplyChain';
import { shipmentStatusVariant } from '@/lib/statusVariants';
import { formatDateIso, formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';

type ContainerDraft = {
  container_number: string;
  seal_number: string;
  size: ContainerSize;
  type: ContainerType;
  gross_weight_kg: string;
  net_weight_kg: string;
  volume_cbm: string;
  notes: string;
};

const newContainerDraft = (): ContainerDraft => ({
  container_number: '', seal_number: '', size: '40ft', type: 'dry',
  gross_weight_kg: '', net_weight_kg: '', volume_cbm: '', notes: '',
});

const landedCostFields = [
  { key: 'freight_cost', label: 'Freight cost' },
  { key: 'insurance_cost', label: 'Insurance cost' },
  { key: 'duties_amount', label: 'Duties' },
  { key: 'brokerage_fee', label: 'Brokerage fee' },
  { key: 'other_charges', label: 'Other charges' },
] as const;
type LandedCostField = typeof landedCostFields[number]['key'];
type LandedCostDraft = Record<LandedCostField, string>;

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
  const [restoreDocId, setRestoreDocId] = useState<string | null>(null);
  const [containerEditorOpen, setContainerEditorOpen] = useState(false);
  const [editingContainerId, setEditingContainerId] = useState<string | null>(null);
  const [containerDraft, setContainerDraft] = useState<ContainerDraft>(newContainerDraft);
  const [containerErrors, setContainerErrors] = useState<Record<string, string>>({});
  const [archiveContainerTarget, setArchiveContainerTarget] = useState<ShipmentContainer | null>(null);
  const [editingLandedCosts, setEditingLandedCosts] = useState(false);
  const [allocationMethod, setAllocationMethod] = useState<LandedCostAllocationMethod>('by_value');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'shipments', id],
    queryFn: () => shipmentsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });
  const {
    data: shipmentOptions,
    isLoading: shipmentOptionsLoading,
    isError: shipmentOptionsError,
    refetch: refetchShipmentOptions,
  } = useQuery({
    queryKey: ['supply-chain', 'shipments', 'options'],
    queryFn: () => shipmentsApi.options(),
    staleTime: 300_000,
  });
  const { data: containerPage, isLoading: containersLoading, isError: containersError, refetch: refetchContainers } = useQuery({
    queryKey: ['supply-chain', 'shipments', id, 'containers', 'with-archived'],
    queryFn: () => shipmentContainersApi.list(id, { per_page: 100, trashed: 'with' }),
    enabled: Boolean(id),
  });
  const containers = containerPage?.data ?? [];
  const docTypeLabels = Object.fromEntries(
    (shipmentOptions?.document_types ?? []).map((option) => [option.value, option.label]),
  );
  const statusOptions = shipmentOptions?.statuses ?? [];
  const statusLabels = new Map(statusOptions.map((option) => [option.value, option.label]));

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
    mutationFn: (file: File) =>
      shipmentsApi.uploadDocument(id, file, docType, docNotes || undefined),
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
      toast.success('Document archived');
      setDeleteDocId(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to archive document'),
  });

  const restoreDoc = useMutation({
    mutationFn: (docId: string) => shipmentsApi.restoreDocument(docId),
    onSuccess: () => {
      toast.success('Document restored');
      setRestoreDocId(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to restore document'),
  });

  const saveContainer = useMutation({
    mutationFn: ({ containerId, data: values }: { containerId: string | null; data: ShipmentContainerData }) =>
      containerId
        ? shipmentContainersApi.update(containerId, values)
        : shipmentContainersApi.create(id, values),
    onSuccess: (_container, variables) => {
      toast.success(variables.containerId ? 'Container updated' : 'Container added');
      setContainerEditorOpen(false);
      setEditingContainerId(null);
      setContainerDraft(newContainerDraft());
      setContainerErrors({});
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id, 'containers'] });
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments'] });
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      const errors = e.response?.data?.errors ?? {};
      setContainerErrors(Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]])));
      toast.error(e.response?.data?.message ?? 'Failed to save container');
    },
  });

  const archiveContainer = useMutation({
    mutationFn: shipmentContainersApi.archive,
    onSuccess: () => {
      toast.success('Container archived');
      setArchiveContainerTarget(null);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id, 'containers'] });
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments'] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to archive container'),
  });

  const restoreContainer = useMutation({
    mutationFn: shipmentContainersApi.restore,
    onSuccess: () => {
      toast.success('Container restored');
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id, 'containers'] });
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
      qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments'] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to restore container'),
  });

  const saveLandedCosts = useMutation({
    mutationFn: (values: LandedCostDraft) => shipmentsApi.updateMeta(id, values),
    onSuccess: async () => {
      setEditingLandedCosts(false);
      await qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
      await qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments'] });
      toast.success('Landed costs saved and allocations recalculated');
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      const validationMessage = Object.values(e.response?.data?.errors ?? {}).flat()[0];
      toast.error(validationMessage ?? e.response?.data?.message ?? 'Failed to save landed costs');
    },
  });

  const calculateLandedCost = useMutation({
    mutationFn: (method: LandedCostAllocationMethod) => shipmentsApi.calculateLandedCost(id, method),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments', id] });
      toast.success('Landed costs calculated');
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to calculate landed costs'),
  });

  useEffect(() => {
    if (data?.allocation_method) setAllocationMethod(data.allocation_method);
  }, [data?.allocation_method]);

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load shipment"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  const nextStatus =
    statusOptions.find((option) => option.value === data.status)?.next_status ?? null;
  const statusOrder = statusOptions
    .filter((option) => !option.is_terminal || option.value === 'received')
    .map((option) => option.value);
  const canManage = can('supply_chain.shipments.manage');
  const landedCostsFrozen = ['accepted', 'partial_accepted'].includes(data.goods_receipt_note?.status ?? '');
  const documents = data.documents ?? [];
  const activeDocuments = documents.filter((document) => !document.deleted_at);
  const canRestoreDocuments = canManage && !['cleared', 'received', 'cancelled'].includes(data.status);
  const canArchiveDocuments = canManage && !['customs', 'cleared', 'received', 'cancelled'].includes(data.status);
  const currentStep = statusOrder.indexOf(data.status);
  const canEditContainers = canManage && !['cleared', 'received', 'cancelled'].includes(data.status);
  const activeContainerCount = containers.filter((container) => !container.deleted_at).length;
  const missingClearanceDocuments = [
    'bill_of_lading', 'commercial_invoice', 'packing_list', 'import_entry', 'boc_release',
  ].filter((type) => !activeDocuments.some((document) => document.document_type === type));
  const clearanceBlockers = nextStatus === 'cleared'
    ? [
        ...missingClearanceDocuments.map((type) => `Upload ${docTypeLabels[type] ?? type.replaceAll('_', ' ')}.`),
        ...(containersLoading
          ? ['Container records are still loading.']
          : containersError
            ? ['Container records could not be verified. Retry the container list.']
            : activeContainerCount === 0
              ? ['Add at least one active container.']
              : []),
      ]
    : [];

  const openContainerEditor = (container?: ShipmentContainer) => {
    setEditingContainerId(container?.id ?? null);
    setContainerDraft(container ? {
      container_number: container.container_number,
      seal_number: container.seal_number ?? '',
      size: container.size ?? '40ft',
      type: container.type ?? 'dry',
      gross_weight_kg: container.gross_weight_kg?.toString() ?? '',
      net_weight_kg: container.net_weight_kg?.toString() ?? '',
      volume_cbm: container.volume_cbm?.toString() ?? '',
      notes: container.notes ?? '',
    } : newContainerDraft());
    setContainerErrors({});
    setContainerEditorOpen(true);
  };

  const submitLandedCosts = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const values = Object.fromEntries(
      landedCostFields.map(({ key }) => [key, String(form.get(key) ?? '0')]),
    ) as LandedCostDraft;
    saveLandedCosts.mutate(values);
  };

  const submitContainer = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const dataToSave: ShipmentContainerData = {
      container_number: containerDraft.container_number.trim(),
      seal_number: containerDraft.seal_number.trim() || null,
      size: containerDraft.size,
      type: containerDraft.type,
      gross_weight_kg: containerDraft.gross_weight_kg || null,
      net_weight_kg: containerDraft.net_weight_kg || null,
      volume_cbm: containerDraft.volume_cbm || null,
      notes: containerDraft.notes.trim() || null,
    };
    saveContainer.mutate({ containerId: editingContainerId, data: dataToSave });
  };

  return (
    <div>
      <PageHeader
        title={
          <span>
            <span className="font-mono">{data.shipment_number}</span>
            <Chip variant={shipmentStatusVariant[data.status]} className="ml-3">
              {statusLabels.get(data.status) ?? data.status.replace('_', ' ')}
            </Chip>
          </span>
        }
        subtitle={data.purchase_order ? `PO ${data.purchase_order.po_number}` : undefined}
        backTo="/supply-chain/shipments"
        backLabel="Shipments"
        actions={
          nextStatus && canManage ? (
            <Button
              variant="primary"
              size="sm"
              icon={<LuArrowRight size={14} />}
              loading={advance.isPending}
              disabled={advance.isPending || clearanceBlockers.length > 0}
              onClick={() => {
                setPendingStatus(nextStatus);
                setStatusNoteOpen(true);
              }}
            >
              Mark {statusLabels.get(nextStatus) ?? nextStatus.replace('_', ' ')}
            </Button>
          ) : undefined
        }
      />

      {shipmentOptionsLoading && !shipmentOptions && (
        <p id="shipment-options-unavailable" role="status" className="mx-5 mt-3 text-sm text-muted">
          Loading shipment options…
        </p>
      )}
      {shipmentOptionsError && (
        <div
          id="shipment-options-unavailable"
          role="alert"
          className="mx-5 mt-3 flex items-center justify-between gap-3 rounded-md border border-danger/30 bg-danger-bg px-3 py-2 text-sm text-danger-fg"
        >
          <span>
            {shipmentOptions
              ? 'Shipment options could not refresh. Last available options remain in use.'
              : 'Shipment options failed to load. Status, document, and container actions are unavailable.'}
          </span>
          <Button variant="secondary" size="sm" onClick={() => void refetchShipmentOptions()}>
            Retry shipment options
          </Button>
        </div>
      )}

      {clearanceBlockers.length > 0 && (
        <div role="status" className="mx-5 mt-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
          <p className="font-medium">Customs clearance blocked</p>
          <ul className="mt-1 list-disc pl-5">
            {clearanceBlockers.map((blocker) => <li key={blocker}>{blocker}</li>)}
          </ul>
        </div>
      )}

      {/* Status timeline */}
      <div className="px-5 py-4 border-b border-default">
        <ol className="flex items-center gap-0">
          {statusOrder.map((s, i) => {
            const isDone = i < currentStep;
            const isCurrent = i === currentStep;
            const isLast = i === statusOrder.length - 1;
            return (
              <li key={s} className="flex items-center">
                <div className="flex flex-col items-center">
                  <div
                    className={[
                      'w-7 h-7 rounded-full flex items-center justify-center text-xs font-medium border-2',
                      isDone
                        ? 'bg-success border-success text-accent-fg'
                        : isCurrent
                          ? 'border-accent text-accent bg-canvas'
                          : 'border-default text-muted bg-canvas',
                    ].join(' ')}
                  >
                    {isDone ? <LuCheck size={12} aria-hidden="true" /> : i + 1}
                  </div>
                  <span
                    className={[
                      'text-2xs mt-1 capitalize',
                      isCurrent
                        ? 'text-accent font-medium'
                        : isDone
                          ? 'text-success-fg'
                          : 'text-muted',
                    ].join(' ')}
                  >
                    {statusLabels.get(s) ?? s.replace('_', ' ')}
                  </span>
                </div>
                {!isLast && (
                  <div
                    className={[
                      'h-0.5 w-10 mb-4',
                      isDone ? 'bg-success-bg' : 'bg-border-default',
                    ].join(' ')}
                  />
                )}
              </li>
            );
          })}
        </ol>
      </div>

      <div className="px-5 pt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          {/* Shipment info */}
          <Panel title="Shipment details">
            <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 text-sm">
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
                <dt className="text-2xs uppercase tracking-wider text-muted">Incoterm</dt>
                <dd>{data.incoterm ?? '—'}</dd>
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

          <Panel
            title="Landed costs"
            meta={data.landed_cost_total !== null ? formatPeso(data.landed_cost_total) : undefined}
            actions={canManage && !landedCostsFrozen && (
              editingLandedCosts ? (
                <Button variant="ghost" size="sm" onClick={() => setEditingLandedCosts(false)}>
                  Cancel edit
                </Button>
              ) : (
                <Button variant="secondary" size="sm" onClick={() => setEditingLandedCosts(true)}>
                  Edit landed costs
                </Button>
              )
            )}
          >
            {editingLandedCosts ? (
              <form onSubmit={submitLandedCosts} className="space-y-3">
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  {landedCostFields.map(({ key, label }) => (
                    <Input
                      key={key}
                      label={label}
                      name={key}
                      type="number"
                      min="0"
                      step="0.01"
                      prefix="₱"
                      defaultValue={data[key] ?? '0.00'}
                      required
                      disabled={saveLandedCosts.isPending}
                    />
                  ))}
                </div>
                <Button type="submit" variant="primary" loading={saveLandedCosts.isPending}>
                  Save landed costs
                </Button>
              </form>
            ) : (
              <dl className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 text-sm">
                {landedCostFields.map(({ key, label }) => (
                  <div key={key}>
                    <dt className="text-2xs uppercase tracking-wider text-muted">{label}</dt>
                    <dd className="font-mono tabular-nums">{formatPeso(data[key])}</dd>
                  </div>
                ))}
              </dl>
            )}

            {landedCostsFrozen && (
              <p role="status" className="mt-3 text-xs text-muted">
                Landed costs are locked because this receipt has accepted stock.
              </p>
            )}

            {canManage && (
              <div className="mt-4 border-t border-subtle pt-3">
                <div className="flex flex-col sm:flex-row sm:items-end gap-2">
                  <Select
                    label="Allocation method"
                    value={allocationMethod}
                    disabled={!shipmentOptions || landedCostsFrozen || calculateLandedCost.isPending}
                    onChange={(event) => setAllocationMethod(event.target.value as LandedCostAllocationMethod)}
                  >
                    {(shipmentOptions?.allocation_methods ?? []).map((option) => (
                      <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                  </Select>
                  <Button
                    variant="secondary"
                    loading={calculateLandedCost.isPending}
                    disabled={!shipmentOptions || landedCostsFrozen || calculateLandedCost.isPending || saveLandedCosts.isPending}
                    onClick={() => calculateLandedCost.mutate(allocationMethod)}
                  >
                    Calculate landed costs
                  </Button>
                </div>
                {!shipmentOptions && (
                  <p role="status" className="mt-2 text-xs text-muted">
                    Load shipment options before calculating landed costs.
                  </p>
                )}
              </div>
            )}

            {data.landed_costs?.length ? (
              <div className="mt-4 overflow-x-auto border-t border-subtle pt-3">
                <table className="min-w-full text-xs tabular-nums">
                  <thead>
                    <tr className="text-left text-muted">
                      <th className="pr-4 py-2 font-medium">Purchase order item</th>
                      <th className="px-3 py-2 text-right font-medium">Freight</th>
                      <th className="px-3 py-2 text-right font-medium">Insurance</th>
                      <th className="px-3 py-2 text-right font-medium">Duties</th>
                      <th className="px-3 py-2 text-right font-medium">Brokerage</th>
                      <th className="px-3 py-2 text-right font-medium">Other</th>
                      <th className="pl-3 py-2 text-right font-medium">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.landed_costs.map((row) => (
                      <tr key={row.id} className="border-t border-subtle">
                        <td className="pr-4 py-2">{row.purchase_order_item?.description ?? row.purchase_order_item?.id ?? 'Purchase order item'}</td>
                        <td className="px-3 py-2 text-right">{formatPeso(row.allocated_freight)}</td>
                        <td className="px-3 py-2 text-right">{formatPeso(row.allocated_insurance)}</td>
                        <td className="px-3 py-2 text-right">{formatPeso(row.allocated_duties)}</td>
                        <td className="px-3 py-2 text-right">{formatPeso(row.allocated_brokerage)}</td>
                        <td className="px-3 py-2 text-right">{formatPeso(row.allocated_other)}</td>
                        <td className="pl-3 py-2 text-right font-medium">{formatPeso(row.total_allocated)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="mt-3 text-xs text-muted" role="status">
                No landed-cost allocations calculated yet.
              </p>
            )}
            {data.landed_cost_calculated_at && (
              <p className="mt-2 text-2xs text-muted">
                Calculated {formatDateTime(data.landed_cost_calculated_at)}
                {data.allocation_method ? ` · ${shipmentOptions?.allocation_methods.find((option) => option.value === data.allocation_method)?.label ?? data.allocation_method}` : ''}
              </p>
            )}
          </Panel>

          {data.goods_receipt_note && (
            <Panel title="Receiving handoff">
              {can('inventory.view') ? (
                <Link
                  to={`/inventory/grns/${data.goods_receipt_note.id}`}
                  className="font-mono text-sm text-accent hover:underline"
                >
                  {data.goods_receipt_note.grn_number}
                </Link>
              ) : (
                <span className="font-mono text-sm">{data.goods_receipt_note.grn_number}</span>
              )}
              <p className="mt-1 text-xs text-muted">
                Status: {data.goods_receipt_note.status.replaceAll('_', ' ')}
              </p>
              {data.goods_receipt_note.status === 'draft' && (
                <p className="mt-2 text-xs text-muted">
                  Warehouse must record received quantities and storage locations. Stock stays unchanged until receipt approval.
                </p>
              )}
            </Panel>
          )}

          <Panel title="Shipment containers" meta={`${activeContainerCount} active`}>
            {containersLoading && <p className="text-sm text-muted">Loading container records…</p>}
            {containersError && (
              <div role="alert" className="flex items-center justify-between gap-3 text-sm text-danger-fg">
                <span>Failed to load container records.</span>
                <Button variant="secondary" size="sm" onClick={() => refetchContainers()}>Retry</Button>
              </div>
            )}
            {!containersLoading && !containersError && activeContainerCount === 0 && (
              <p className="text-sm text-warning-fg" role="status">
                Add at least one active container before customs clearance.
              </p>
            )}
            {!containersLoading && !containersError && containers.length > 0 && (
              <ul className="divide-y divide-subtle">
                {containers.map((container) => (
                  <li key={container.id} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-sm font-medium">{container.container_number}</span>
                        {container.deleted_at && <Chip variant="neutral">Archived</Chip>}
                      </div>
                      <p className="mt-1 text-xs text-muted">
                        {container.seal_number ? `Seal ${container.seal_number} · ` : ''}
                        {[container.size_label, container.type_label].filter(Boolean).join(' · ')}
                        {container.gross_weight_kg !== null && ` · Gross ${container.gross_weight_kg.toLocaleString()} kg`}
                        {container.net_weight_kg !== null && ` · Net ${container.net_weight_kg.toLocaleString()} kg`}
                        {container.volume_cbm !== null && ` · ${container.volume_cbm.toLocaleString()} m³`}
                      </p>
                      {container.notes && <p className="mt-1 text-xs text-muted whitespace-pre-line">{container.notes}</p>}
                    </div>
                    {canEditContainers && (
                      <div className="flex items-center gap-1 shrink-0">
                        {container.deleted_at ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            icon={<LuArchiveRestore size={14} />}
                            loading={restoreContainer.isPending}
                            disabled={restoreContainer.isPending}
                            onClick={() => restoreContainer.mutate(container.id)}
                          >
                            Restore
                          </Button>
                        ) : (
                          <>
                            <Button
                              variant="ghost"
                              size="sm"
                              iconOnly
                              icon={<LuPencil size={14} />}
                              aria-label={`Edit container ${container.container_number}`}
                              aria-describedby={!shipmentOptions ? 'shipment-options-unavailable' : undefined}
                              disabled={!shipmentOptions}
                              onClick={() => openContainerEditor(container)}
                            />
                            <Button
                              variant="ghost"
                              size="sm"
                              iconOnly
                              icon={<LuTrash2 size={14} />}
                              aria-label={`Archive container ${container.container_number}`}
                              onClick={() => setArchiveContainerTarget(container)}
                              className="text-muted hover:text-danger-fg"
                            />
                          </>
                        )}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {canEditContainers ? (
              <div className="mt-3 border-t border-subtle pt-3">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={!shipmentOptions}
                  aria-describedby={!shipmentOptions ? 'shipment-options-unavailable' : undefined}
                  onClick={() => openContainerEditor()}
                >
                  Add container
                </Button>
              </div>
            ) : canManage ? (
              <p className="mt-3 border-t border-subtle pt-3 text-xs text-muted">
                Container records are frozen after customs clearance or shipment cancellation.
              </p>
            ) : null}
          </Panel>

          {/* Documents */}
          <Panel
            title="Shipment documents"
            meta={
              documents.length > 0
                ? `${documents.length} file${documents.length === 1 ? '' : 's'}`
                : undefined
            }
          >
            {documents.length === 0 && (
              <div className="text-xs text-muted mb-3 px-3 py-2 bg-subtle rounded-md">
                No documents uploaded yet. Upload import documents (B/L, commercial invoice, packing
                list, etc.) here.
              </div>
            )}

            {documents.length > 0 && activeDocuments.length === 0 && (
              <p className="text-sm text-warning-fg mb-3" role="status">
                No active documents. Archived files do not satisfy customs clearance.
              </p>
            )}

            {documents.length > 0 && (
              <ul className="space-y-2 mb-4">
                {documents.map((doc) => (
                  <li
                    key={doc.id}
                    className="flex items-center gap-3 px-3 py-2.5 border border-subtle rounded-md bg-canvas"
                  >
                    <LuFileText size={16} className="shrink-0 text-muted" />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium truncate">
                          {doc.original_filename ?? 'Document'}
                        </span>
                        <Chip variant="neutral">
                          {docTypeLabels[doc.document_type] ?? doc.document_type}
                        </Chip>
                        {doc.deleted_at && <Chip variant="neutral">Archived</Chip>}
                      </div>
                      <div className="text-2xs text-muted mt-0.5">
                        {formatBytes(doc.file_size_bytes)}
                        {doc.uploader && ` · ${doc.uploader.name}`}
                        {doc.uploaded_at && ` · ${formatDateIso(doc.uploaded_at, '')}`}
                      </div>
                      {doc.notes && <div className="text-xs text-muted mt-0.5">{doc.notes}</div>}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      {doc.url && !doc.deleted_at && (
                        <LinkButton
                          className="text-xs"
                          onClick={() =>
                            void downloadAuthenticatedFile(doc.url!, {
                              filename: doc.original_filename ?? undefined,
                              errorMessage: 'Failed to download the shipment document.',
                            })
                          }
                        >
                          Download
                        </LinkButton>
                      )}
                      {doc.deleted_at
                        ? canRestoreDocuments && (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              icon={<LuArchiveRestore size={14} />}
                              aria-label={`Restore document ${doc.original_filename ?? doc.id}`}
                              onClick={() => setRestoreDocId(doc.id)}
                              className="text-muted hover:text-primary"
                            >
                              Restore
                            </Button>
                          )
                        : canArchiveDocuments && (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              iconOnly
                              icon={<LuTrash2 size={14} />}
                              aria-label={`Archive document ${doc.original_filename ?? doc.id}`}
                              onClick={() => setDeleteDocId(doc.id)}
                              className="text-muted hover:text-danger-fg"
                            />
                          )}
                    </div>
                  </li>
                ))}
              </ul>
            )}

            {canManage && !['cleared', 'received', 'cancelled'].includes(data.status) && (
              <div className="border-t border-subtle pt-3">
                <div className="grid grid-cols-2 gap-2 mb-2">
                  <Select
                    label="Document type"
                    value={docType}
                    disabled={!shipmentOptions}
                    onChange={(e) => setDocType(e.target.value as ShipmentDocumentType)}
                  >
                    {(shipmentOptions?.document_types ?? []).map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                  <Input
                    label="Document notes"
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
                    disabled={uploadDoc.isPending || !shipmentOptions}
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) uploadDoc.mutate(file);
                      e.target.value = '';
                    }}
                  />
                  <span className="flex items-center justify-center gap-2 cursor-pointer w-full py-3 border-2 border-dashed border-default rounded-md text-sm text-muted hover:border-accent hover:text-accent transition-colors">
                    <LuUpload size={16} />
                    {uploadDoc.isPending
                      ? 'Uploading…'
                      : shipmentOptions
                        ? 'Click to upload (PDF, image, Excel — max 20 MB)'
                        : 'Load shipment options to upload documents'}
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
                icon={<LuDownload size={14} />}
                className="w-full justify-start"
                onClick={() =>
                  void downloadAuthenticatedFile(
                    `/api/v1/supply-chain/shipments/${id}/packing-list`,
                    { openInNewTab: true, errorMessage: 'Failed to generate the packing list.' },
                  )
                }
              >
                Draft packing list
              </Button>
              <Button
                variant="secondary"
                size="sm"
                icon={<LuDownload size={14} />}
                className="w-full justify-start"
                onClick={() =>
                  void downloadAuthenticatedFile(
                    `/api/v1/supply-chain/shipments/${id}/commercial-invoice`,
                    {
                      openInNewTab: true,
                      errorMessage: 'Failed to generate the commercial invoice.',
                    },
                  )
                }
              >
                Draft commercial invoice
              </Button>
            </div>
            <p className="text-2xs text-muted mt-2">Auto-generated from shipment + PO data</p>
          </Panel>

          {/* Timestamps */}
          <Panel title="Record">
            <dl className="text-sm space-y-2">
              <div className="flex justify-between gap-2">
                <dt className="text-muted">Created</dt>
                <dd className="font-mono tabular-nums text-xs">{formatDateIso(data.created_at)}</dd>
              </div>
              <div className="flex justify-between gap-2">
                <dt className="text-muted">Updated</dt>
                <dd className="font-mono tabular-nums text-xs">{formatDateIso(data.updated_at)}</dd>
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
        onClose={() => {
          setStatusNoteOpen(false);
          setStatusNote('');
          setPendingStatus(null);
        }}
        title={`Mark as ${pendingStatus?.replace('_', ' ') ?? ''}`}
      >
        <div className="space-y-3">
          <Textarea
            label="Note (optional)"
            value={statusNote}
            onChange={(e) => setStatusNote(e.target.value)}
            rows={3}
            placeholder="Add a note about this status change…"
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button
              variant="secondary"
              onClick={() => {
                setStatusNoteOpen(false);
                setStatusNote('');
                setPendingStatus(null);
              }}
            >
              Cancel
            </Button>
            <Button
              variant="primary"
              icon={<LuArrowRight size={14} />}
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

      {/* Archive document confirmation */}
      <ConfirmDialog
        isOpen={!!deleteDocId}
        onClose={() => setDeleteDocId(null)}
        onConfirm={() => {
          if (deleteDocId) removeDoc.mutate(deleteDocId);
        }}
        title="Archive this document?"
        description="The file will be archived and can be restored later."
        confirmLabel="Archive"
        variant="danger"
        pending={removeDoc.isPending}
      />

      {/* Restore document confirmation */}
      <ConfirmDialog
        isOpen={!!restoreDocId}
        onClose={() => setRestoreDocId(null)}
        onConfirm={() => {
          if (restoreDocId) restoreDoc.mutate(restoreDocId);
        }}
        title="Restore this document?"
        description="The file will be restored and available for download again."
        confirmLabel="Restore"
        pending={restoreDoc.isPending}
      />

      <Modal
        isOpen={containerEditorOpen}
        onClose={() => {
          if (saveContainer.isPending) return;
          setContainerEditorOpen(false);
          setContainerErrors({});
        }}
        title={editingContainerId ? 'Edit container' : 'Add container'}
        size="lg"
        closeOnOverlayClick={!saveContainer.isPending}
      >
        <form onSubmit={submitContainer} className="space-y-3">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Container number"
              value={containerDraft.container_number}
              onChange={(event) => setContainerDraft((draft) => ({ ...draft, container_number: event.target.value }))}
              maxLength={50}
              required
              error={containerErrors.container_number}
              className="font-mono"
            />
            <Input
              label="Seal number"
              value={containerDraft.seal_number}
              onChange={(event) => setContainerDraft((draft) => ({ ...draft, seal_number: event.target.value }))}
              maxLength={50}
              error={containerErrors.seal_number}
              className="font-mono"
            />
            <Select
              label="Container size"
              value={containerDraft.size}
              onChange={(event) => setContainerDraft((draft) => ({ ...draft, size: event.target.value as ContainerSize }))}
              required
              error={containerErrors.size}
            >
              {(shipmentOptions?.container_sizes ?? []).map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </Select>
            <Select
              label="Container type"
              value={containerDraft.type}
              onChange={(event) => setContainerDraft((draft) => ({ ...draft, type: event.target.value as ContainerType }))}
              required
              error={containerErrors.type}
            >
              {(shipmentOptions?.container_types ?? []).map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </Select>
            <Input
              label="Gross weight (kg)"
              type="number"
              min="0"
              max="99999999.99"
              step="0.01"
              value={containerDraft.gross_weight_kg}
              onChange={(event) => setContainerDraft((draft) => ({ ...draft, gross_weight_kg: event.target.value }))}
              error={containerErrors.gross_weight_kg}
            />
            <Input
              label="Net weight (kg)"
              type="number"
              min="0"
              max="99999999.99"
              step="0.01"
              value={containerDraft.net_weight_kg}
              onChange={(event) => setContainerDraft((draft) => ({ ...draft, net_weight_kg: event.target.value }))}
              error={containerErrors.net_weight_kg}
            />
            <Input
              label="Volume (m³)"
              type="number"
              min="0"
              max="99999.999"
              step="0.001"
              value={containerDraft.volume_cbm}
              onChange={(event) => setContainerDraft((draft) => ({ ...draft, volume_cbm: event.target.value }))}
              error={containerErrors.volume_cbm}
            />
          </div>
          <Textarea
            label="Container notes"
            value={containerDraft.notes}
            onChange={(event) => setContainerDraft((draft) => ({ ...draft, notes: event.target.value }))}
            rows={3}
            maxLength={1000}
            error={containerErrors.notes}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="secondary"
              disabled={saveContainer.isPending}
              onClick={() => setContainerEditorOpen(false)}
            >
              Cancel
            </Button>
            <Button type="submit" variant="primary" loading={saveContainer.isPending} disabled={saveContainer.isPending}>
              Save container
            </Button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        isOpen={!!archiveContainerTarget}
        onClose={() => setArchiveContainerTarget(null)}
        onConfirm={() => {
          if (archiveContainerTarget) archiveContainer.mutate(archiveContainerTarget.id);
        }}
        title="Archive container?"
        description={archiveContainerTarget
          ? `Container ${archiveContainerTarget.container_number} will be archived and can be restored before customs clearance.`
          : undefined}
        confirmLabel="Archive"
        variant="danger"
        pending={archiveContainer.isPending}
      />
    </div>
  );
}
