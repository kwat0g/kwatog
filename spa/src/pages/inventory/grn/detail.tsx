import { Fragment, useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import {
  LuTriangleAlert,
  LuCircleCheck,
  LuRefreshCw,
  LuCircleX,
  LuPackageCheck,
  LuSend,
  LuMessageSquare,
} from '@/lib/icons';
import { billsApi } from '@/api/accounting/bills';
import { grnApi } from '@/api/inventory/grn';
import { supplierActivityApi } from '@/api/b2b/supplier-activity';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ChainHeader } from '@/components/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { GrnSupplierDocumentsPanel } from './components/GrnSupplierDocumentsPanel';
import { useChainProgress } from '@/hooks/useChainProgress';
import { formatDate } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { buildP2pChain } from '@/lib/chains';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import type { GrnItem } from '@/types/inventory';
import type { SupplierActivityShipment } from '@/types/supplierActivity';

interface FinalizeLine {
  location_id?: string;
  quantity_received: string;
  supplier_lot_reference: string;
  expiry_date: string;
}

const emptyFinalizeLine: FinalizeLine = {
  quantity_received: '0',
  supplier_lot_reference: '',
  expiry_date: '',
};

export default function GrnDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [confirmAccept, setConfirmAccept] = useState(false);
  const [confirmPartial, setConfirmPartial] = useState(false);
  const [confirmFinalize, setConfirmFinalize] = useState(false);
  const [confirmPostBill, setConfirmPostBill] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [rejectRemainderOpen, setRejectRemainderOpen] = useState(false);
  const [acceptMap, setAcceptMap] = useState<Record<string, string>>({});
  const [finalizeInput, setFinalizeInput] = useState<Record<string, FinalizeLine>>({});
  const [selectedSupplierShipment, setSelectedSupplierShipment] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'grn', id],
    queryFn: () => grnApi.show(id),
    enabled: !!id,
  });
  useChainProgress('grn', id, ['inventory', 'grn', id]);
  const { data: grnOptions } = useQuery({
    queryKey: ['inventory', 'grn', 'options'],
    queryFn: grnApi.options,
    staleTime: 300_000,
  });
  const {
    data: supplierEvidence,
    isLoading: supplierEvidenceLoading,
    isError: supplierEvidenceError,
    refetch: refetchSupplierEvidence,
  } = useQuery({
    queryKey: ['inventory', 'grn', id, 'supplier-documents'],
    queryFn: () => supplierActivityApi.forGoodsReceipt(id),
    enabled: !!id && data?.status === 'draft',
  });
  const supplierShipments = supplierEvidence?.shipments.filter((shipment) => shipment.can_edit !== false) ?? [];
  const shipmentSummary = (shipment: SupplierActivityShipment) =>
    shipment.items
      ?.map((item) => {
        const line = data?.items?.find(
          (entry) => entry.purchase_order_item_id === item.purchase_order_item_id,
        );
        return `${line?.item?.code ?? 'Item'}: ${formatQuantity(item.quantity_shipped)} ${line?.purchase_order_unit ?? line?.item?.unit_of_measure ?? ''}`;
      })
      .join(' · ') || 'Quantity not recorded';
  const supplierShipmentId =
    supplierShipments.length === 1
      ? supplierShipments[0].id
      : supplierShipments.some((shipment) => shipment.id === selectedSupplierShipment)
        ? selectedSupplierShipment
        : '';
  const {
    data: warehouses,
    isLoading: warehousesLoading,
    isError: warehousesError,
    refetch: refetchWarehouses,
  } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });
  const locations = useMemo(
    () =>
      (warehouses ?? []).flatMap((w) =>
        (w.is_active ? (w.zones ?? []) : []).flatMap((z) =>
          (z.locations ?? [])
            .filter(
              (l) =>
                l.is_active &&
                !l.is_blocked &&
                z.zone_type !== 'quarantine' &&
                z.zone_type !== 'scrap',
            )
            .map((l) => ({
              id: l.id,
              label: `${w.code}-${z.code}-${l.code}`,
              sub: `${w.name} / ${z.name}`,
            })),
        ),
      ),
    [warehouses],
  );
  const storedBinsFor = (line: GrnItem) =>
    (line.item?.stored_bins ?? []).filter((bin) =>
      locations.some((location) => location.id === bin.id),
    );
  const receivingBinId = (line: GrnItem) => {
    const selected = finalizeInput[line.id]?.location_id;
    if (selected !== undefined) return selected;
    const storedBins = storedBinsFor(line);
    return storedBins.length === 1 ? storedBins[0].id : '';
  };

  const accept = useMutation({
    mutationFn: (map?: Record<string, string>) => grnApi.accept(id, map),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      toast.success('GRN accepted, stock updated.');
      setConfirmAccept(false);
      setConfirmPartial(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to accept GRN.'),
  });
  const reject = useMutation({
    mutationFn: (reason: string) => grnApi.reject(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      toast.success('GRN rejected.');
      setRejectOpen(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to reject GRN.'),
  });
  const rejectRemainder = useMutation({
    mutationFn: (reason: string) => grnApi.rejectRemainder(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders'] });
      toast.success('Remainder rejected — supplier return opened');
      setRejectRemainderOpen(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to reject remainder.'),
  });
  const finalize = useMutation({
    mutationFn: () =>
      grnApi.finalize(id, {
        supplier_shipment_id: supplierShipmentId || null,
        items: (data?.items ?? []).flatMap((l) => {
          const v = finalizeInput[l.id];
          const locationId = receivingBinId(l);
          if (!v || !locationId || !(Number(v.quantity_received) > 0)) return [];
          return [
            {
              purchase_order_item_id: l.purchase_order_item_id,
              location_id: locationId,
              quantity_received: v.quantity_received,
              received_uom_code: l.item?.unit_of_measure,
              supplier_lot_reference: v.supplier_lot_reference.trim() || undefined,
              expiry_date: v.expiry_date || undefined,
            },
          ];
        }),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      toast.success('GRN finalized — goods sent to incoming QC.');
      setConfirmFinalize(false);
    },
    onError: async (e: AxiosError<{ message?: string }>) => {
      // Finalization may commit even when the response is lost. Reconcile the
      // authoritative state before offering another action; keep finalizeInput
      // untouched so a genuine validation failure can be corrected and retried.
      setConfirmFinalize(false);
      try {
        const current = await grnApi.show(id);
        qc.setQueryData(['inventory', 'grn', id], current);
        if (current.status === 'draft') {
          toast.error(
            e.response?.data?.message ??
              'GRN remains in Draft. Review the entered quantities and try again.',
          );
        } else {
          toast.success(
            `${current.grn_number} is now ${current.status_label ?? current.status}. Current handoff details are shown.`,
          );
        }
      } catch {
        void qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
        toast.error(
          'Could not confirm the GRN status. Use Retry to check the current handoff before submitting again.',
        );
      }
    },
  });

  const updateFinalizeLine = (lineId: string, patch: Partial<FinalizeLine>) => {
    setFinalizeInput((current) => ({
      ...current,
      [lineId]: { ...emptyFinalizeLine, ...current[lineId], ...patch },
    }));
  };
  const retryIncomingQc = useMutation({
    mutationFn: () => grnApi.retryIncomingQc(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      toast.success('Incoming QC trigger retried.');
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to retry incoming QC.'),
  });
  const retryGl = useMutation({
    mutationFn: () => grnApi.retryGl(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      toast.success('GRN GL posting retried.');
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to retry GRN GL posting.'),
  });
  // 2026-08-08 — post the auto-created draft bill straight from the receipt.
  const postBill = useMutation({
    mutationFn: () => {
      if (!data?.bill?.id) throw new Error('No bill to post.');
      return billsApi.postDraft(data.bill.id);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
      qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
      toast.success('Draft bill posted to AP + GL.');
      setConfirmPostBill(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to post bill.'),
  });

  // A rejected remainder closes the receipt; the API refuses further acceptance.
  const isEditable =
    data?.status === 'pending_qc' ||
    (data?.status === 'partial_accepted' && !data?.remainder_rejected_at);
  const isDraft = data?.status === 'draft';

  useEffect(() => {
    // Pending GRNs start at zero accepted; continuation is cumulative and starts
    // from the quantities already posted into inventory.
    if (isEditable) {
      const initial: Record<string, string> = {};
      data?.items?.forEach((l) => {
        initial[l.id] =
          data.status === 'partial_accepted' ? l.quantity_accepted : l.quantity_received;
      });
      setAcceptMap(initial);
    } else {
      setAcceptMap({});
    }
    // This intentionally resets only when the document/status changes, so a
    // background refetch does not overwrite quantities the operator is editing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isEditable, data?.id, data?.status]);

  if (isLoading) return <SkeletonTable rows={6} columns={5} />;
  if (isError || !data)
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load GRN"
        action={<Button onClick={() => refetch()}>Retry</Button>}
      />
    );

  const hasPartial =
    isEditable &&
    data.items?.some((l) => {
      const qty = acceptMap[l.id];
      return (
        data.status === 'pending_qc' &&
        qty !== undefined &&
        Number(qty) < Number(l.quantity_received)
      );
    });
  const hasAcceptanceIncrease =
    isEditable &&
    data.items?.some(
      (l) => Number(acceptMap[l.id] ?? l.quantity_accepted) > Number(l.quantity_accepted),
    );
  const acceptanceComplete =
    isEditable &&
    data.items?.every(
      (l) => Number(acceptMap[l.id] ?? l.quantity_accepted) >= Number(l.quantity_received),
    );
  const enteredReceiptLines = (data.items ?? []).filter(
    (line) => Number(finalizeInput[line.id]?.quantity_received) > 0,
  );
  const hasInvalidReceiptQuantity = (data.items ?? []).some((line) => {
    const quantity = finalizeInput[line.id]?.quantity_received;
    return (
      quantity !== undefined &&
      quantity !== '' &&
      (!/^\d+(\.\d{1,3})?$/.test(quantity) ||
        (line.quantity_outstanding != null && Number(quantity) > Number(line.quantity_outstanding)))
    );
  });
  const finalizeReady =
    isDraft &&
    supplierShipmentId !== '' &&
    !supplierEvidenceLoading &&
    !supplierEvidenceError &&
    !warehousesLoading &&
    !warehousesError &&
    enteredReceiptLines.length > 0 &&
    !hasInvalidReceiptQuantity &&
    enteredReceiptLines.every((line) => !!receivingBinId(line));
  const incomingQcNeedsAttention =
    data.status === 'pending_qc' &&
    data.incoming_qc_handoff &&
    data.incoming_qc_handoff.status !== 'generated' &&
    data.incoming_qc_handoff.status !== 'not_required';
  // The API refuses acceptance until every incoming inspection has passed, and
  // a passing verdict accepts the GRN on its own — so while QC is open there is
  // nothing to accept here, only a 422 behind the button.
  const awaitingIncomingQc =
    data.status === 'pending_qc' &&
    (!!incomingQcNeedsAttention ||
      (!!data.qc_inspection && data.qc_inspection.status !== 'passed') ||
      (data.items ?? []).some((l) => l.inspection && l.inspection.status !== 'passed'));
  const canAcceptHere = isEditable && !awaitingIncomingQc;
  const columnCount = isDraft ? 4 : 8 + (canAcceptHere ? 1 : 0);
  const glNeedsAttention =
    (data.status === 'accepted' || data.status === 'partial_accepted') &&
    !data.journal_entry &&
    can('accounting.journal.post');

  const variant = (
    {
      draft: 'neutral',
      pending_qc: 'warning',
      accepted: 'success',
      partial_accepted: 'info',
      rejected: 'danger',
    } as const
  )[data.status];

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{data.grn_number}</span>}
        subtitle={[data.purchase_order?.po_number, data.vendor?.name].filter(Boolean).join(' · ')}
        backTo="/inventory/grn"
        backLabel="GRNs"
        actions={
          <div className="flex items-center gap-2">
            <Chip variant={variant}>
              {grnOptions?.statuses?.find((option) => option.value === data.status)?.label ??
                data.status}
            </Chip>
            {data.status !== 'draft' && can('return_management.manage') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuMessageSquare size={14} />}
                onClick={() =>
                  navigate(
                    `/return-management/cases/new?source_kind=grn&source_id=${encodeURIComponent(id)}`,
                  )
                }
              >
                Report a problem
              </Button>
            )}
            {incomingQcNeedsAttention && can('quality.inspections.manage') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuRefreshCw size={14} />}
                onClick={() => retryIncomingQc.mutate()}
                loading={retryIncomingQc.isPending}
              >
                Retry incoming QC
              </Button>
            )}
            {glNeedsAttention && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuRefreshCw size={14} />}
                onClick={() => retryGl.mutate()}
                loading={retryGl.isPending}
              >
                Retry GL posting
              </Button>
            )}
            {isEditable && can('inventory.grn.create') && (
              <>
                {data.status === 'pending_qc' && (
                  <Button
                    variant="secondary"
                    size="xs"
                    icon={<LuCircleX size={14} />}
                    onClick={() => setRejectOpen(true)}
                  >
                    Reject
                  </Button>
                )}
                {!canAcceptHere ? null : data.status === 'partial_accepted' || hasPartial ? (
                  <Button
                    variant="primary"
                    size="sm"
                    icon={<LuCircleCheck size={14} />}
                    onClick={() => setConfirmPartial(true)}
                    loading={accept.isPending}
                    disabled={accept.isPending || !hasAcceptanceIncrease}
                  >
                    {data.status === 'partial_accepted'
                      ? acceptanceComplete
                        ? 'Accept remaining'
                        : 'Accept additional'
                      : 'Partial accept'}
                  </Button>
                ) : (
                  <Button
                    variant="primary"
                    size="sm"
                    icon={<LuCircleCheck size={14} />}
                    onClick={() => setConfirmAccept(true)}
                    loading={accept.isPending}
                    disabled={accept.isPending}
                  >
                    Accept
                  </Button>
                )}
                {data.can_reject_remainder && (
                  <Button
                    variant="secondary"
                    size="xs"
                    icon={<LuCircleX size={14} />}
                    onClick={() => setRejectRemainderOpen(true)}
                    loading={rejectRemainder.isPending}
                  >
                    Reject remainder
                  </Button>
                )}
              </>
            )}
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        <Panel title="Procure-to-pay chain" bodyClassName="px-4 py-3">
          <ChainHeader
            stretch
            showStatusMessages={false}
            steps={buildP2pChain({
              pr: data.purchase_order?.purchase_request
                ? {
                    id: data.purchase_order.purchase_request.id,
                    number: data.purchase_order.purchase_request.pr_number,
                  }
                : null,
              po: data.purchase_order
                ? { id: data.purchase_order.id, number: data.purchase_order.po_number }
                : null,
              grns: [
                {
                  id: data.id,
                  grn_number: data.grn_number,
                  status: data.status,
                  received_date: data.received_date,
                },
              ],
              bills: data.bill
                ? [
                    {
                      id: data.bill.id,
                      bill_number: data.bill.bill_number,
                      status: data.bill.status,
                    },
                  ]
                : [],
            })}
          />
        </Panel>
        <Panel title="Receipt details">
          <dl className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-y-3 gap-x-6 text-sm">
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">PO</dt>
              <dd className="font-mono">
                {data.purchase_order ? (
                  <Link
                    to={`/purchasing/purchase-orders/${data.purchase_order.id}`}
                    className="text-accent hover:underline"
                  >
                    {data.purchase_order.po_number}
                  </Link>
                ) : (
                  '—'
                )}
              </dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Vendor</dt>
              <dd>{data.vendor?.name ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Received</dt>
              <dd className={isDraft ? 'text-muted' : 'font-mono'}>
                {isDraft ? 'Awaiting goods' : formatDate(data.received_date)}
              </dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Received by</dt>
              {/* A draft is only an expectation; its creator did not receive anything. */}
              <dd>{isDraft ? '—' : (data.receiver?.name ?? '—')}</dd>
            </div>
            {data.accepted_at && (
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Accepted</dt>
                <dd className="font-mono">
                  {formatDate(data.accepted_at)} · {data.acceptor?.name}
                </dd>
              </div>
            )}
            {data.rejected_reason && (
              <div className="col-span-4">
                <dt className="text-2xs uppercase tracking-wider text-muted">Rejection reason</dt>
                <dd className="text-danger-fg">{data.rejected_reason}</dd>
              </div>
            )}
            {data.remainder_rejected_at && (
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Remainder rejected</dt>
                <dd className="font-mono">
                  {formatDate(data.remainder_rejected_at)} · {data.remainder_rejected_by?.name}
                </dd>
              </div>
            )}
            {data.remainder_rejected_reason && (
              <div className="col-span-4">
                <dt className="text-2xs uppercase tracking-wider text-muted">
                  Remainder rejection reason
                </dt>
                <dd>{data.remainder_rejected_reason}</dd>
              </div>
            )}
            {/* Incoming QC runs per line; each line shows its own inspection below. */}
            {data.qc_inspection && !(data.items ?? []).some((l) => l.inspection) && (
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Incoming QC</dt>
                <dd>
                  {can('quality.inspections.view') ? (
                    <Link
                      to={`/quality/inspections/${data.qc_inspection.id}`}
                      className="font-mono text-accent hover:underline"
                    >
                      {data.qc_inspection.inspection_number}
                    </Link>
                  ) : (
                    <span className="font-mono">{data.qc_inspection.inspection_number}</span>
                  )}{' '}
                  <Chip variant={chipVariantForStatus(data.qc_inspection.status)}>
                    {data.qc_inspection.status_label ?? data.qc_inspection.status ?? '—'}
                  </Chip>
                </dd>
              </div>
            )}
            {data.journal_entry && can('accounting.journal.view') && (
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Inventory GL</dt>
                <dd>
                  <Link
                    to={`/accounting/journal-entries/${data.journal_entry.id}`}
                    className="font-mono text-accent hover:underline"
                  >
                    {data.journal_entry.entry_number}
                  </Link>{' '}
                  <Chip variant={chipVariantForStatus(data.journal_entry.status)}>
                    {data.journal_entry.status_label ?? data.journal_entry.status ?? '—'}
                  </Chip>
                </dd>
              </div>
            )}
          </dl>
        </Panel>
        {isDraft && (
          <div className="flex items-center gap-3 rounded-md border border-info/40 bg-info-bg/10 px-4 py-3 text-sm">
            <LuPackageCheck size={16} className="shrink-0 text-info-fg" />
            <div>
              <div className="font-medium">Awaiting delivery</div>
              <div className="text-muted">
                When goods arrive, match their shipment, enter actual received quantities and bins,
                then finalize for incoming QC.
              </div>
            </div>
          </div>
        )}
        {incomingQcNeedsAttention && (
          <div className="flex items-center gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
            <LuTriangleAlert size={16} className="shrink-0 text-warning-fg" />
            <div className="flex-1">
              <div className="font-medium">Incoming QC handoff needs attention</div>
              <div className="text-muted">
                {data.incoming_qc_handoff?.message ??
                  'No incoming Quality inspection has been staged yet.'}
              </div>
            </div>
            {can('quality.inspections.manage') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuRefreshCw size={14} />}
                onClick={() => retryIncomingQc.mutate()}
                loading={retryIncomingQc.isPending}
              >
                Retry trigger
              </Button>
            )}
          </div>
        )}
        {glNeedsAttention && (
          <div className="flex items-center gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
            <LuTriangleAlert size={16} className="shrink-0 text-warning-fg" />
            <div className="flex-1">
              <div className="font-medium">GRN GL handoff needs attention</div>
              <div className="text-muted">
                This accepted receipt has no linked inventory journal entry. Retry the idempotent
                handoff after Accounting is available.
              </div>
            </div>
            <Button
              variant="secondary"
              size="sm"
              icon={<LuRefreshCw size={14} />}
              onClick={() => retryGl.mutate()}
              loading={retryGl.isPending}
            >
              Retry posting
            </Button>
          </div>
        )}
        {data.bill && (
          <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
            <LuCircleCheck size={16} className="shrink-0 text-success-fg" />
            <div className="flex-1">
              <div className="font-medium">Supplier bill auto-created</div>
              <div className="text-muted">
                A draft AP bill was staged from this accepted receipt —{' '}
                <Link
                  to={`/accounting/bills/${data.bill.id}`}
                  className="text-accent hover:underline font-mono"
                >
                  {data.bill.bill_number}
                </Link>{' '}
                · {formatPeso(Number(data.bill.total_amount))}{' '}
                <Chip variant={chipVariantForStatus(data.bill.status)}>
                  {data.bill.status_label ?? data.bill.status}
                </Chip>.
                {data.bill.status !== 'draft'
                  ? ' Posted to the ledger.'
                  : ' Review and post to record the payable.'}
              </div>
            </div>
            {data.bill.status === 'draft' && can('accounting.bills.create') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuSend size={14} />}
                onClick={() => setConfirmPostBill(true)}
              >
                Post bill
              </Button>
            )}
          </div>
        )}
        <div className="grid grid-cols-1 gap-4 2xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
          <Panel title={isDraft ? 'Record received goods' : 'Line items'}>
            {isDraft && can('inventory.grn.create') && (
              <div className="mb-4 border-b border-default pb-4">
                <p className="mb-2 text-xs font-medium text-secondary">Supplier shipment</p>
                {supplierEvidenceLoading ? (
                  <p className="text-sm text-muted">Loading supplier shipments…</p>
                ) : supplierEvidenceError ? (
                  <div
                    role="alert"
                    className="flex flex-wrap items-center gap-2 text-sm text-danger-fg"
                  >
                    Could not load supplier shipments.
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => void refetchSupplierEvidence()}
                    >
                      Retry
                    </Button>
                  </div>
                ) : supplierShipments.length === 0 ? (
                  <p className="text-sm text-muted">
                    No supplier shipment reported yet. Ask the supplier to record dispatch before
                    receiving.
                  </p>
                ) : supplierShipments.length === 1 ? (
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <Chip variant="success">Matched</Chip>
                    <span className="font-medium">
                      {supplierShipments[0].tracking_number ?? 'Dispatch reported'}
                    </span>
                    <span className="text-muted">
                      Shipped {formatDate(supplierShipments[0].shipped_date)}
                    </span>
                    <span className="text-muted">
                      {shipmentSummary(supplierShipments[0])} dispatched
                    </span>
                  </div>
                ) : (
                  <Select
                    label="Supplier shipment"
                    value={selectedSupplierShipment}
                    onChange={(event) => setSelectedSupplierShipment(event.target.value)}
                    required
                    containerClassName="max-w-lg"
                  >
                    <option value="">Select the shipment that arrived…</option>
                    {supplierShipments.map((shipment, index) => (
                      <option key={shipment.id} value={shipment.id}>
                        {shipment.tracking_number ?? `Shipment ${index + 1}`}
                        {shipment.shipped_date
                          ? ` · ${formatDate(shipment.shipped_date)}`
                          : ''} · {shipmentSummary(shipment)} dispatched
                      </option>
                    ))}
                  </Select>
                )}
                <p className="mt-2 text-xs text-muted">
                  Record only what physically arrived. If this is less than the supplier reported,
                  the GRN shows the difference and the unreceived PO quantity stays open.
                </p>
              </div>
            )}
            {isDraft && warehousesError && (
              <div role="alert" className="mb-4 flex items-center gap-2 text-sm text-danger-fg">
                Could not load storage bins.
                <Button variant="secondary" size="sm" onClick={() => void refetchWarehouses()}>
                  Retry
                </Button>
              </div>
            )}
            <div className="overflow-x-auto" role="region" aria-label="GRN line items" tabIndex={0}>
              <table className={`${tableCls} ${isDraft ? 'min-w-[640px]' : 'min-w-[760px]'}`}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Item</Th>
                    {isDraft ? (
                      <>
                        <Th align="right">Still due</Th>
                        <Th>Storage bin</Th>
                        <Th align="right">Received now</Th>
                      </>
                    ) : (
                      <>
                        <Th>Location</Th>
                        <Th align="right">Original received (base)</Th>
                        <Th align="right">Returned (base)</Th>
                        <Th align="right">Net received (base)</Th>
                        {canAcceptHere && <Th align="right">Accept (base)</Th>}
                        <Th align="right">Accepted (base)</Th>
                        <Th align="right">Unit cost</Th>
                        <Th align="right">Total</Th>
                      </>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {data.items?.map((l) => (
                    <Fragment key={l.id}>
                      <tr key={l.id} className={trCls}>
                        <Td>
                          <span className="font-mono">{l.item?.code}</span>
                          <div className="text-2xs text-muted">{l.item?.name}</div>
                          <Chip variant={l.item?.quality_plan_ready ? 'success' : 'warning'}>
                            {l.item?.quality_plan_ready ? 'QC plan' : 'fallback QC'}
                          </Chip>
                        </Td>
                        {isDraft && (
                          <Td align="right" mono>
                            {l.quantity_outstanding != null
                              ? `${formatQuantity(l.quantity_outstanding)} ${l.item?.unit_of_measure ?? ''}`
                              : '—'}
                          </Td>
                        )}
                        {isDraft ? (
                          <Td>
                            <Select
                              fieldSize="sm"
                              containerClassName="w-44"
                              aria-label={`Bin ${l.item?.code ?? l.id}`}
                              value={receivingBinId(l)}
                              disabled={warehousesLoading || warehousesError}
                              onChange={(e) =>
                                updateFinalizeLine(l.id, { location_id: e.target.value })
                              }
                            >
                              <option value="">
                                {warehousesLoading ? 'Loading bins…' : 'Select bin…'}
                              </option>
                              {storedBinsFor(l).length > 0 && (
                                <optgroup label="Bins holding this item">
                                  {storedBinsFor(l).map((bin) => (
                                    <option key={bin.id} value={bin.id}>
                                      {bin.full_code} · {formatQuantity(bin.quantity)} in stock
                                    </option>
                                  ))}
                                </optgroup>
                              )}
                              <optgroup label="Other active bins">
                                {locations
                                  .filter(
                                    (loc) => !storedBinsFor(l).some((bin) => bin.id === loc.id),
                                  )
                                  .map((loc) => (
                                    <option key={loc.id} value={loc.id}>
                                      {loc.label}
                                    </option>
                                  ))}
                              </optgroup>
                            </Select>
                            <div className="mt-1 text-2xs text-muted">
                              {storedBinsFor(l).length === 1
                                ? 'Suggested from current stock'
                                : storedBinsFor(l).length > 1
                                  ? 'Choose a bin holding this item'
                                  : 'No stocked bin yet; choose a location'}
                            </div>
                          </Td>
                        ) : (
                          <Td mono>{l.location?.full_code ?? '—'}</Td>
                        )}
                        {isDraft && (
                          <Td align="right">
                            <Input
                              type="number"
                              min="0"
                              step="0.001"
                              max={l.quantity_outstanding ?? undefined}
                              aria-label={`Entered quantity ${l.item?.code ?? l.id}`}
                              value={finalizeInput[l.id]?.quantity_received ?? '0'}
                              onChange={(e) =>
                                updateFinalizeLine(l.id, { quantity_received: e.target.value })
                              }
                              error={
                                finalizeInput[l.id]?.quantity_received &&
                                l.quantity_outstanding != null &&
                                Number(finalizeInput[l.id].quantity_received) >
                                  Number(l.quantity_outstanding)
                                  ? `Maximum ${formatQuantity(l.quantity_outstanding)} ${l.item?.unit_of_measure ?? ''}`
                                  : undefined
                              }
                              className="w-32 h-7 text-right font-mono"
                            />
                            <div className="mt-0.5 text-2xs text-muted">
                              {l.item?.unit_of_measure ?? 'Base units'}
                            </div>
                          </Td>
                        )}
                        {!isDraft && (
                          <>
                            <Td align="right" mono>
                              {formatQuantity(l.original_received_quantity ?? l.quantity_received)}{' '}
                              {l.item?.unit_of_measure}
                            </Td>
                            <Td align="right" mono>
                              {formatQuantity(l.returned_quantity ?? 0)} {l.item?.unit_of_measure}
                            </Td>
                            <Td align="right" mono>
                              {formatQuantity(l.net_received_quantity ?? l.quantity_received)}{' '}
                              {l.item?.unit_of_measure}
                            </Td>
                          </>
                        )}
                        {canAcceptHere && (
                          <Td align="right">
                            <Input
                              type="number"
                              min={data.status === 'partial_accepted' ? l.quantity_accepted : '0'}
                              step="0.001"
                              max={l.quantity_received}
                              value={
                                acceptMap[l.id] ??
                                (data.status === 'partial_accepted'
                                  ? l.quantity_accepted
                                  : l.quantity_received)
                              }
                              onChange={(e) => {
                                const v = e.target.value;
                                setAcceptMap((m) => ({ ...m, [l.id]: v }));
                              }}
                              className="w-24 h-7 text-right font-mono"
                            />
                          </Td>
                        )}
                        {!isDraft && (
                          <>
                            <Td align="right" mono>
                              {formatQuantity(l.quantity_accepted)} {l.item?.unit_of_measure}
                            </Td>
                            <Td align="right" mono>
                              {Number(l.unit_cost).toFixed(4)}
                            </Td>
                            <Td align="right" mono>
                              {formatPeso(Number(l.quantity_received) * Number(l.unit_cost))}
                            </Td>
                          </>
                        )}
                      </tr>
                      <tr className="border-b border-subtle bg-subtle/40">
                        <Td colSpan={columnCount}>
                          {isDraft ? (
                            <details className="text-sm">
                              <summary className="cursor-pointer text-accent hover:underline">
                                Add supplier lot or expiry (optional)
                              </summary>
                              <p className="mt-2 text-xs text-muted">
                                Copy these from the item label when present. OGAMI creates its own
                                lot number automatically.
                              </p>
                              <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <Input
                                  fieldSize="sm"
                                  label="Supplier lot / batch"
                                  maxLength={100}
                                  value={finalizeInput[l.id]?.supplier_lot_reference ?? ''}
                                  onChange={(e) =>
                                    updateFinalizeLine(l.id, {
                                      supplier_lot_reference: e.target.value,
                                    })
                                  }
                                />
                                <Input
                                  fieldSize="sm"
                                  label="Expiry date"
                                  type="date"
                                  value={finalizeInput[l.id]?.expiry_date ?? ''}
                                  onChange={(e) =>
                                    updateFinalizeLine(l.id, { expiry_date: e.target.value })
                                  }
                                />
                              </div>
                            </details>
                          ) : (
                            <div className="flex flex-wrap gap-x-5 gap-y-1 text-2xs text-muted">
                              {l.quantity_received_in_uom !== null &&
                              l.received_uom_conversion_factor !== null ? (
                                <span>
                                  Entered{' '}
                                  <span className="font-mono text-primary">
                                    {formatQuantity(l.quantity_received_in_uom)}{' '}
                                    {l.received_uom_code || l.item?.unit_of_measure}
                                  </span>
                                  {' · 1 '}
                                  {l.received_uom_code || l.item?.unit_of_measure}
                                  {' = '}
                                  {Number(l.received_uom_conversion_factor).toFixed(6)}{' '}
                                  {l.item?.unit_of_measure}
                                </span>
                              ) : (
                                <span>
                                  Stored in base units: {formatQuantity(l.quantity_received)}{' '}
                                  {l.item?.unit_of_measure}.
                                  {l.received_uom_code &&
                                    ` Original unit ${l.received_uom_code}; entered quantity was not recorded.`}
                                </span>
                              )}
                              <span>
                                Lot:{' '}
                                <span className="font-mono text-primary">
                                  {l.lot_number ?? '—'}
                                </span>
                              </span>
                              <span>
                                Supplier lot:{' '}
                                <span className="font-mono text-primary">
                                  {l.supplier_lot_reference ?? '—'}
                                </span>
                              </span>
                              <span>
                                Expiry:{' '}
                                <span className="font-mono text-primary">
                                  {l.expiry_date ?? '—'}
                                </span>
                              </span>
                              <span>
                                Moisture:{' '}
                                <span className="font-mono text-primary">
                                  {l.moisture_percentage ?? '—'}
                                  {l.moisture_percentage ? '%' : ''}
                                </span>
                              </span>
                              <span>
                                COA:{' '}
                                <span className="font-mono text-primary">
                                  {l.coa_document_path ?? '—'}
                                </span>{' '}
                                ·{' '}
                                {l.coa_verified
                                  ? 'Verified by Quality'
                                  : 'Pending Quality verification'}
                              </span>
                              {l.inspection && (
                                <span>
                                  QC:{' '}
                                  {can('quality.inspections.view') ? (
                                    <Link
                                      to={`/quality/inspections/${l.inspection.id}`}
                                      className="font-mono text-accent hover:underline"
                                    >
                                      {l.inspection.inspection_number}
                                    </Link>
                                  ) : (
                                    <span className="font-mono text-primary">
                                      {l.inspection.inspection_number}
                                    </span>
                                  )}{' '}
                                  <Chip variant={chipVariantForStatus(l.inspection.status)}>
                                    {l.inspection.status_label ?? l.inspection.status ?? '—'}
                                  </Chip>
                                </span>
                              )}
                            </div>
                          )}
                        </Td>
                      </tr>
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </div>
            {isDraft && can('inventory.grn.create') && (
              <div className="mt-4 flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm text-secondary" role="status">
                  {!supplierShipmentId
                    ? 'Wait for a supplier shipment, or select the shipment that arrived.'
                    : enteredReceiptLines.length === 0
                      ? 'Enter the quantity that physically arrived.'
                      : hasInvalidReceiptQuantity
                        ? 'Correct the received quantity before finalizing.'
                        : !finalizeReady
                          ? 'Assign a storage bin to every line with received quantity.'
                          : 'Ready to finalize. Incoming QC follows this step.'}
                </p>
                <Button
                  variant="primary"
                  size="sm"
                  icon={<LuPackageCheck size={14} />}
                  onClick={() => setConfirmFinalize(true)}
                  loading={finalize.isPending}
                  disabled={!finalizeReady}
                >
                  Finalize receiving
                </Button>
              </div>
            )}
          </Panel>
          <div className="space-y-4">
            {data.supplier_shipment && (
              <Panel
                title="Dispatch versus receipt"
                actions={
                  data.status !== 'draft' &&
                  data.supplier_shipment.items.some((item) => Number(item.difference) < 0) &&
                  can('inventory.grn.create') &&
                  !can('return_management.manage') ? (
                    <Button
                      variant="secondary"
                      size="sm"
                      icon={<LuMessageSquare size={14} />}
                      onClick={() =>
                        navigate(
                          `/return-management/cases/new?source_kind=grn&source_id=${encodeURIComponent(id)}`,
                        )
                      }
                    >
                      Report shortage
                    </Button>
                  ) : undefined
                }
              >
                <p className="mb-2 text-xs text-muted">
                  Supplier quantities are declarations. Warehouse receipt records the actual
                  arrival.
                </p>
                {data.supplier_shipment.items.map((item) => (
                  <div
                    key={item.purchase_order_item_id}
                    className="flex flex-wrap gap-x-3 gap-y-1 py-1 text-sm"
                  >
                    <span>
                      Shipped {formatQuantity(item.quantity_shipped)} {item.unit}
                    </span>
                    <span>
                      Received {formatQuantity(item.quantity_received)} {item.unit}
                    </span>
                    {Number(item.difference) !== 0 && (
                      <span className="text-warning-fg">
                        {Number(item.difference) < 0 ? 'Short by' : 'Over by'}{' '}
                        {formatQuantity(Math.abs(Number(item.difference)))} {item.unit}
                      </span>
                    )}
                  </div>
                ))}
              </Panel>
            )}
            {data.purchase_order && <GrnSupplierDocumentsPanel grnId={data.id} />}
          </div>
        </div>
        {data.items?.some((line) => (line.active_quality_holds?.length ?? 0) > 0) && (
          <Panel title="Quality follow-up">
            {data.items.flatMap((line) =>
              (line.active_quality_holds ?? []).map((hold) => (
                <p key={hold.id} className="text-sm text-warning-fg">
                  {line.item?.code}: {formatQuantity(hold.quantity ?? 0)} held ·{' '}
                  {can('quality.ncr.view') ? (
                    <Link className="text-accent hover:underline" to={`/quality/ncrs/${hold.id}`}>
                      {hold.ncr_number}
                    </Link>
                  ) : (
                    hold.ncr_number
                  )}
                </p>
              )),
            )}
          </Panel>
        )}
      </div>

      <ConfirmDialog
        isOpen={confirmFinalize}
        onClose={() => setConfirmFinalize(false)}
        onConfirm={() => finalize.mutate()}
        title="Finalize receiving?"
        description="Assigning these bins and quantities records the goods as received. The GRN moves to incoming QC for inspection before stock is updated."
        confirmLabel="Finalize GRN"
        variant="primary"
        pending={finalize.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAccept}
        onClose={() => setConfirmAccept(false)}
        onConfirm={() => accept.mutate(undefined)}
        title="Accept this GRN?"
        description="Accepting will post stock movements to update inventory levels and weighted-average cost. This cannot be undone."
        confirmLabel="Accept GRN"
        variant="primary"
        pending={accept.isPending}
      />

      <ConfirmDialog
        isOpen={confirmPartial}
        onClose={() => setConfirmPartial(false)}
        onConfirm={() => accept.mutate(acceptMap)}
        title="Partially accept this GRN?"
        description="Acceptance is cumulative. Only the increase since the last decision moves into inventory; previously accepted stock is never duplicated or reduced."
        confirmLabel={data.status === 'partial_accepted' ? 'Accept quantities' : 'Partial accept'}
        variant="primary"
        pending={accept.isPending}
      />

      <ReasonDialog
        isOpen={rejectOpen}
        onClose={() => setRejectOpen(false)}
        onConfirm={(reason) => reject.mutate(reason)}
        title="Reject this GRN?"
        description="The vendor delivery will be flagged as rejected. Reason is recorded for audit."
        reasonLabel="Rejection reason"
        reasonPlaceholder="e.g. Material failed incoming inspection (mould flash on pin 3)"
        minLength={10}
        confirmLabel="Reject"
        variant="danger"
        pending={reject.isPending}
      />

      <ReasonDialog
        isOpen={rejectRemainderOpen}
        onClose={() => setRejectRemainderOpen(false)}
        onConfirm={(reason) => rejectRemainder.mutate(reason)}
        title="Reject remainder?"
        description="Received quantities not yet accepted go back to the supplier on a return. Accepted stock is unaffected, and the PO expects the returned quantity again unless you short-close it."
        reasonLabel="Reason for remainder rejection"
        reasonPlaceholder="e.g. Balance failed incoming inspection — wrong resin grade"
        minLength={10}
        confirmLabel="Reject remainder"
        variant="danger"
        pending={rejectRemainder.isPending}
      />

      <ConfirmDialog
        isOpen={confirmPostBill}
        onClose={() => setConfirmPostBill(false)}
        onConfirm={() => postBill.mutate()}
        title="Post draft bill to AP + GL?"
        description="Posting records the payable: it builds and posts the AP/expense journal entry (debit expense + VAT input, credit AP) and flips the bill to Unpaid. Review the auto-created amounts before posting."
        confirmLabel="Post bill"
        variant="primary"
        pending={postBill.isPending}
      />
    </div>
  );
}
