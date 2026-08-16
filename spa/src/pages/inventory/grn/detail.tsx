import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { LuTriangleAlert, LuCircleCheck, LuRefreshCw, LuCircleX, LuPackageCheck, LuSend } from '@/lib/icons';
import { billsApi } from '@/api/accounting/bills';
import { grnApi } from '@/api/inventory/grn';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
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
import { useChainProgress } from '@/hooks/useChainProgress';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { buildP2pChain } from '@/lib/chains';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function GrnDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [confirmAccept, setConfirmAccept] = useState(false);
 const [confirmPartial, setConfirmPartial] = useState(false);
 const [confirmFinalize, setConfirmFinalize] = useState(false);
 const [confirmPostBill, setConfirmPostBill] = useState(false);
 const [rejectOpen, setRejectOpen] = useState(false);
 const [acceptMap, setAcceptMap] = useState<Record<string, string>>({});
 const [finalizeInput, setFinalizeInput] = useState<Record<string, { location_id: string; quantity_received: string }>>({});

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
 const { data: warehouses } = useQuery({
 queryKey: ['inventory', 'warehouse', 'tree'],
 queryFn: () => warehouseApi.tree(),
 });
 const locations = useMemo(
 () => (warehouses ?? []).flatMap((w) =>
 (w.zones ?? []).flatMap((z) => (z.locations ?? []).map((l) => ({
  id: l.id,
  label: `${w.code}-${z.code}-${l.code}`,
  sub: `${w.name} / ${z.name}`,
 }))),
 ),
 [warehouses],
 );

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
 const finalize = useMutation({
 mutationFn: () => grnApi.finalize(id, {
  items: (data?.items ?? [])
   .flatMap((l) => {
    const v = finalizeInput[l.id];
    if (!v?.location_id || !(Number(v.quantity_received) > 0)) return [];
    return [{
     purchase_order_item_id: l.purchase_order_item_id,
     location_id: v.location_id,
     quantity_received: v.quantity_received,
    }];
   }),
 }),
 onSuccess: () => {
  qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
  toast.success('GRN finalized — goods sent to incoming QC.');
  setConfirmFinalize(false);
 },
 onError: (e: AxiosError<{ message?: string }>) =>
  toast.error(e.response?.data?.message ?? 'Failed to finalize GRN.'),
 });
 const retryIncomingQc = useMutation({
  mutationFn: () => grnApi.retryIncomingQc(id),
  onSuccess: () => {
   qc.invalidateQueries({ queryKey: ['inventory', 'grn', id] });
   toast.success('Incoming QC trigger retried.');
  },
  onError: (e: AxiosError<{ message?: string }>) =>
   toast.error(e.response?.data?.message ?? 'Failed to retry incoming QC.'),
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

 const isEditable = data?.status === 'pending_qc' || data?.status === 'partial_accepted';
 const isDraft = data?.status === 'draft';

 useEffect(() => {
 // Pending GRNs start at zero accepted; continuation is cumulative and starts
 // from the quantities already posted into inventory.
 if (isEditable) {
  const initial: Record<string, string> = {};
  data?.items?.forEach((l) => {
   initial[l.id] = data.status === 'partial_accepted' ? l.quantity_accepted : l.quantity_received;
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
 if (isError || !data) return (
 <EmptyState icon="alert-circle" title="Failed to load GRN" action={<Button onClick={() => refetch()}>Retry</Button>} />
 );

 const hasPartial = isEditable && data.items?.some((l) => {
  const qty = acceptMap[l.id];
  return data.status === 'pending_qc' && qty !== undefined && Number(qty) < Number(l.quantity_received);
 });
 const hasAcceptanceIncrease = isEditable && data.items?.some((l) =>
  Number(acceptMap[l.id] ?? l.quantity_accepted) > Number(l.quantity_accepted),
 );
 const acceptanceComplete = isEditable && data.items?.every((l) =>
  Number(acceptMap[l.id] ?? l.quantity_accepted) >= Number(l.quantity_received),
 );
 const finalizeReady = isDraft && (data.items ?? []).some((l) => {
  const v = finalizeInput[l.id];
  return !!v?.location_id && Number(v.quantity_received) > 0;
 });
 const incomingQcNeedsAttention = data.status === 'pending_qc'
  && data.incoming_qc_handoff
  && data.incoming_qc_handoff.status !== 'generated'
  && data.incoming_qc_handoff.status !== 'not_required';

 const variant = ({ draft: 'neutral', pending_qc: 'warning', accepted: 'success', partial_accepted: 'info', rejected: 'danger' } as const)[data.status];

 return (
 <div>
  <PageHeader
  title={<span className="font-mono">{data.grn_number}</span>}
  backTo="/inventory/grn" backLabel="GRNs"
  actions={
   <div className="flex items-center gap-2">
   <Chip variant={variant}>{grnOptions?.statuses.find((option) => option.value === data.status)?.label ?? data.status}</Chip>
   {incomingQcNeedsAttention && can('quality.inspections.manage') && (
    <Button variant="secondary" size="sm" icon={<LuRefreshCw size={14} />} onClick={() => retryIncomingQc.mutate()} loading={retryIncomingQc.isPending}>
     Retry incoming QC
    </Button>
   )}
   {isDraft && can('inventory.grn.create') && (
    <Button variant="primary" size="sm" icon={<LuPackageCheck size={14} />}
     onClick={() => setConfirmFinalize(true)} loading={finalize.isPending}
     disabled={!finalizeReady}>Finalize receiving</Button>
   )}
   {isEditable && can('inventory.grn.create') && (
    <>
    {data.status === 'pending_qc' && <Button variant="secondary" size="xs" icon={<LuCircleX size={14} />} onClick={() => setRejectOpen(true)}>Reject</Button>}
    {data.status === 'partial_accepted' || hasPartial ? (
     <Button variant="primary" size="sm" icon={<LuCircleCheck size={14} />} onClick={() => setConfirmPartial(true)}
      loading={accept.isPending} disabled={accept.isPending || !hasAcceptanceIncrease}>{data.status === 'partial_accepted' ? (acceptanceComplete ? 'Accept remaining' : 'Accept additional') : 'Partial accept'}</Button>
    ) : (
     <Button variant="primary" size="sm" icon={<LuCircleCheck size={14} />} onClick={() => setConfirmAccept(true)}
      loading={accept.isPending} disabled={accept.isPending}>Accept</Button>
    )}
    </>
   )}
   </div>
  }
  />
  <div className="px-5 py-4 space-y-4">
  {isDraft && (
   <div className="flex items-center gap-3 rounded-md border border-info/40 bg-info-bg/10 px-4 py-3 text-sm">
   <LuPackageCheck size={16} className="shrink-0 text-info-fg" />
   <div>
    <div className="font-medium">Expected receipt — awaiting goods</div>
    <div className="text-muted">
    This GRN was auto-created when the PO was sent to the supplier. When the goods arrive,
    assign a bin and the received quantity per line, then finalize — the GRN moves to incoming QC.
    </div>
   </div>
   </div>
  )}
  {incomingQcNeedsAttention && (
   <div className="flex items-center gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
    <LuTriangleAlert size={16} className="shrink-0 text-warning-fg" />
    <div className="flex-1">
     <div className="font-medium">Incoming QC handoff needs attention</div>
     <div className="text-muted">{data.incoming_qc_handoff?.message ?? 'No incoming Quality inspection has been staged yet.'}</div>
    </div>
    {can('quality.inspections.manage') && (
     <Button variant="secondary" size="sm" icon={<LuRefreshCw size={14} />} onClick={() => retryIncomingQc.mutate()} loading={retryIncomingQc.isPending}>
      Retry trigger
     </Button>
    )}
   </div>
  )}
  {data.bill && (
  <div className="flex items-center gap-3 rounded-md border border-success/40 bg-success-bg/10 px-4 py-3 text-sm">
  <LuCircleCheck size={16} className="shrink-0 text-success-fg" />
  <div className="flex-1">
  <div className="font-medium">Supplier bill auto-created</div>
  <div className="text-muted">
  A draft AP bill was staged from this accepted receipt —{' '}
  <Link to={`/accounting/bills/${data.bill.id}`} className="text-accent hover:underline font-mono">{data.bill.bill_number}</Link>
  {' '}· {formatPeso(Number(data.bill.total_amount))} ·{' '}{data.bill.status_label ?? data.bill.status}.
  {data.bill.status !== 'draft' ? ' Posted to the ledger.' : ' Review and post to record the payable.'}
  </div>
  </div>
  {data.bill.status === 'draft' && can('accounting.bills.create') && (
  <Button variant="secondary" size="sm" icon={<LuSend size={14} />} onClick={() => setConfirmPostBill(true)}>
  Post bill
  </Button>
  )}
  </div>
  )}
  <Panel title="Procure-to-pay chain">
   {/* 2026-08-08 — compact cross-document stepper: the whole chain at a glance.
    This receipt is the GRN step; upstream (PR/PO) and downstream (Bill/Paid)
    stay visible and clickable from here. */}
   <ChainHeader steps={buildP2pChain({
    pr: data.purchase_order?.purchase_request
    ? { id: data.purchase_order.purchase_request.id, number: data.purchase_order.purchase_request.pr_number }
    : null,
    po: data.purchase_order ? { id: data.purchase_order.id, number: data.purchase_order.po_number } : null,
    grns: [{ id: data.id, grn_number: data.grn_number, status: data.status, received_date: data.received_date }],
    bills: data.bill
    ? [{ id: data.bill.id, bill_number: data.bill.bill_number, status: data.bill.status }]
    : [],
   })} />
  </Panel>
  <Panel title="Header">
   <dl className="grid grid-cols-4 gap-y-3 gap-x-6 text-sm">
   <div><dt className="text-2xs uppercase tracking-wider text-muted">PO</dt><dd className="font-mono">{data.purchase_order?.po_number ?? '—'}</dd></div>
   <div><dt className="text-2xs uppercase tracking-wider text-muted">Vendor</dt><dd>{data.vendor?.name ?? '—'}</dd></div>
   <div><dt className="text-2xs uppercase tracking-wider text-muted">Received</dt><dd className="font-mono">{formatDate(data.received_date)}</dd></div>
   <div><dt className="text-2xs uppercase tracking-wider text-muted">Received by</dt><dd>{data.receiver?.name ?? '—'}</dd></div>
   {data.accepted_at && <div><dt className="text-2xs uppercase tracking-wider text-muted">Accepted</dt><dd className="font-mono">{formatDate(data.accepted_at)} · {data.acceptor?.name}</dd></div>}
   {data.rejected_reason && <div className="col-span-4"><dt className="text-2xs uppercase tracking-wider text-muted">Rejection reason</dt><dd className="text-danger-fg">{data.rejected_reason}</dd></div>}
   </dl>
  </Panel>
  <Panel title="Line items">
   <div className="overflow-x-auto">
   <table className={`${tableCls} min-w-[760px]`}>
   <thead><tr className={theadTrCls}>
    <Th>Item</Th>
    <Th>Location</Th>
    {isDraft && <Th>Bin (assign)</Th>}
    <Th align="right">Received</Th>
    {isDraft && <Th align="right">Qty received</Th>}
    {isEditable && <Th align="right">Accept qty</Th>}
    <Th align="right">Accepted</Th>
    <Th align="right">Unit cost</Th>
    <Th align="right">Total</Th>
   </tr></thead>
   <tbody>
   {data.items?.map((l) => (
    <tr key={l.id} className={trCls}>
    <Td>
     <span className="font-mono">{l.item?.code}</span>
     <div className="text-2xs text-muted">{l.item?.name}</div>
     <Chip variant={l.item?.quality_plan_ready ? 'success' : 'warning'}>{l.item?.quality_plan_ready ? 'QC plan' : 'fallback QC'}</Chip>
    </Td>
    <Td mono>{l.location?.full_code ?? '—'}</Td>
    {isDraft && (
     <Td>
     <Select
      fieldSize="sm"
      containerClassName="w-44"
      aria-label={`Bin ${l.item?.code ?? l.id}`}
      value={finalizeInput[l.id]?.location_id ?? ''}
      onChange={(e) => setFinalizeInput((m) => ({ ...m, [l.id]: { ...m[l.id], location_id: e.target.value, quantity_received: m[l.id]?.quantity_received ?? '0' } }))}
     >
      <option value="">Select bin…</option>
      {locations.map((loc) => (
       <option key={loc.id} value={loc.id}>{loc.label}</option>
      ))}
     </Select>
     </Td>
    )}
    <Td align="right" mono>{Number(l.quantity_received).toFixed(3)}</Td>
    {isDraft && (
     <Td align="right">
     <Input
      type="number" min="0" step="0.001"
      aria-label={`Qty ${l.item?.code ?? l.id}`}
      value={finalizeInput[l.id]?.quantity_received ?? '0'}
      onChange={(e) => setFinalizeInput((m) => ({ ...m, [l.id]: { ...m[l.id], quantity_received: e.target.value, location_id: m[l.id]?.location_id ?? '' } }))}
      className="w-24 h-7 text-right font-mono"
     />
     </Td>
    )}
    {isEditable && (
     <Td align="right">
     <Input
      type="number" min={data.status === 'partial_accepted' ? l.quantity_accepted : '0'} step="0.001" max={l.quantity_received}
      value={acceptMap[l.id] ?? (data.status === 'partial_accepted' ? l.quantity_accepted : l.quantity_received)}
      onChange={(e) => {
       const v = e.target.value;
       setAcceptMap((m) => ({ ...m, [l.id]: v }));
      }}
      className="w-24 h-7 text-right font-mono"
     />
     </Td>
    )}
    <Td align="right" mono>{Number(l.quantity_accepted).toFixed(3)}</Td>
    <Td align="right" mono>{Number(l.unit_cost).toFixed(4)}</Td>
    <Td align="right" mono>{(Number(l.quantity_received) * Number(l.unit_cost)).toFixed(2)}</Td>
    </tr>
   ))}
   </tbody>
   </table>
   </div>
  </Panel>
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
  minLength={10}  confirmLabel="Reject"
  variant="danger"
  pending={reject.isPending}
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
