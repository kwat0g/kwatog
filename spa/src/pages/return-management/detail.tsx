import { useState, lazy, Suspense } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { returnManagementApi } from '@/api/returnManagement';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso, formatInt } from '@/lib/formatNumber';

const DisposeDialog = lazy(() => import('./dispose'));

const STATUS_VARIANT: Record<string, ChipVariant> = {
  draft: 'neutral',
  pending_approval: 'warning',
  approved: 'info',
  received: 'info',
  inspected: 'purple',
  completed: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
};

const CONDITION_LABELS: Record<string, string> = {
  new: 'New',
  used: 'Used',
  damaged: 'Damaged',
  defective: 'Defective',
  obsolete: 'Obsolete',
};

const REASON_LABELS: Record<string, string> = {
  defective: 'Defective product',
  damaged: 'Damaged in transit',
  wrong_item: 'Wrong item shipped',
  excess: 'Excess quantity',
  customer_change: 'Customer changed mind',
  quality_issue: 'Quality issue',
  other: 'Other',
};

const RESOLUTION_LABELS: Record<string, string> = {
  replace: 'Replace',
  refund: 'Refund',
  credit_note: 'Credit Note',
  scrap: 'Scrap',
  return_to_vendor: 'Return to Vendor',
};

/** Design-token dot class for each timeline event. */
const TIMELINE_DOT: Record<string, string> = {
  created: 'bg-muted',
  approved: 'bg-info',
  received: 'bg-accent',
  inspected: 'bg-purple',
  completed: 'bg-success',
  rejected: 'bg-danger',
  cancelled: 'bg-muted',
};

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

export default function ReturnRequestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { can } = usePermission();

  const [confirm, setConfirm] = useState<'submit' | 'approve' | 'receive' | 'inspect' | 'complete' | 'cancel' | null>(null);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [locationId, setLocationId] = useState('');
  const [showDispose, setShowDispose] = useState(false);

  const { data: rma, isLoading, isError, refetch } = useQuery({
    queryKey: ['return-request', id],
    queryFn: () => returnManagementApi.get(id!),
    enabled: !!id,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['return-request', id] });

  const submitMut = useMutation({
    mutationFn: () => returnManagementApi.submit(id!),
    onSuccess: () => { invalidate(); toast.success('RMA submitted for approval.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to submit RMA.')),
  });

  const approveMut = useMutation({
    mutationFn: () => returnManagementApi.approve(id!),
    onSuccess: () => { invalidate(); toast.success('RMA approved.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to approve RMA.')),
  });

  const receiveMut = useMutation({
    mutationFn: () => returnManagementApi.receive(id!),
    onSuccess: () => { invalidate(); toast.success('Receipt recorded.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to record receipt.')),
  });

  const inspectMut = useMutation({
    mutationFn: () => returnManagementApi.inspect(id!),
    onSuccess: () => { invalidate(); toast.success('Inspection completed.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to complete inspection.')),
  });

  const completeMut = useMutation({
    mutationFn: (locId?: string) => returnManagementApi.complete(id!, locId as string),
    onSuccess: () => { invalidate(); toast.success('RMA completed.'); setConfirm(null); setLocationId(''); },
    onError: (e) => toast.error(errMsg(e, 'Failed to complete RMA.')),
  });

  const rejectMut = useMutation({
    mutationFn: (reason: string) => returnManagementApi.reject(id!, reason),
    onSuccess: () => { invalidate(); toast.success('RMA rejected.'); setRejectOpen(false); },
    onError: (e) => toast.error(errMsg(e, 'Failed to reject RMA.')),
  });

  const cancelMut = useMutation({
    mutationFn: () => returnManagementApi.cancel(id!),
    onSuccess: () => { invalidate(); toast.success('RMA cancelled.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to cancel RMA.')),
  });

  const availableActions = (status?: string): Array<{ key: string; label: string; variant?: 'primary' | 'danger' | 'default' }> => {
    if (!status) return [];
    switch (status) {
      case 'draft': return [
        { key: 'submit', label: 'Submit for Approval', variant: 'primary' },
        { key: 'cancel', label: 'Cancel', variant: 'danger' },
      ];
      case 'pending_approval': return [
        { key: 'approve', label: 'Approve', variant: 'primary' },
        { key: 'reject', label: 'Reject', variant: 'danger' },
      ];
      case 'approved': return [
        { key: 'receive', label: 'Record Receipt', variant: 'primary' },
        { key: 'reject', label: 'Reject', variant: 'danger' },
      ];
      case 'received': return [
        { key: 'inspect', label: 'Complete Inspection', variant: 'primary' },
        { key: 'reject', label: 'Reject', variant: 'danger' },
      ];
      case 'inspected': return [
        ...(rma?.disposition_status !== 'disposed' ? [{ key: 'dispose', label: 'Dispose Items', variant: 'primary' as const }] : []),
        { key: 'complete', label: 'Complete RMA', variant: 'primary' as const },
        { key: 'reject', label: 'Reject', variant: 'danger' as const },
      ];
      default: return [];
    }
  };

  const handleAction = (key: string) => {
    if (key === 'reject') {
      setRejectOpen(true);
    } else if (key === 'dispose') {
      setShowDispose(true);
    } else if (key === 'complete') {
      setConfirm('complete');
    } else {
      setConfirm(key as typeof confirm);
    }
  };

  const executeConfirm = () => {
    switch (confirm) {
      case 'submit': submitMut.mutate(); break;
      case 'approve': approveMut.mutate(); break;
      case 'receive': receiveMut.mutate(); break;
      case 'inspect': inspectMut.mutate(); break;
      case 'cancel': cancelMut.mutate(); break;
      default: break;
    }
  };

  const confirmPending =
    confirm === 'submit' ? submitMut.isPending
    : confirm === 'approve' ? approveMut.isPending
    : confirm === 'receive' ? receiveMut.isPending
    : confirm === 'inspect' ? inspectMut.isPending
    : confirm === 'cancel' ? cancelMut.isPending
    : false;

  const CONFIRM_META: Record<string, { title: string; description: string; label: string; variant: 'primary' | 'danger' }> = {
    submit: { title: 'Submit RMA for approval?', description: 'The RMA enters the approval chain. Edits are no longer allowed until approved or rejected.', label: 'Submit', variant: 'primary' },
    approve: { title: 'Approve this RMA?', description: 'Approval is recorded against your account in the audit log.', label: 'Approve', variant: 'primary' },
    receive: { title: 'Record receipt of returned items?', description: 'Marks the items as physically received and ready for inspection.', label: 'Record Receipt', variant: 'primary' },
    inspect: { title: 'Complete inspection?', description: 'Marks the inspection as done. Items can then be disposed and the RMA completed.', label: 'Complete Inspection', variant: 'primary' },
    cancel: { title: 'Cancel this RMA?', description: 'Cancellation is permanent. The RMA cannot be reopened.', label: 'Yes, cancel RMA', variant: 'danger' },
  };

  if (isLoading) return <SkeletonDetail />;

  if (isError || !rma) {
    return <EmptyState icon="alert-circle" title="Failed to load return request" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }

  const actions = can('return_management.manage') ? availableActions(rma.status) : [];

  // Build timeline entries from timestamp fields
  const timeline: Array<{ key: string; label: string; at: string | null | undefined; by?: { name: string } | null }> = [
    { key: 'created', label: 'Created', at: rma.created_at, by: rma.creator },
    { key: 'approved', label: 'Approved', at: rma.approved_at, by: rma.approved_by },
    { key: 'received', label: 'Received', at: rma.received_at },
    { key: 'inspected', label: 'Inspected', at: rma.inspected_at },
    { key: 'completed', label: 'Completed', at: rma.completed_at, by: rma.approved_by },
    { key: 'rejected', label: 'Rejected', at: rma.rejected_at },
    { key: 'cancelled', label: 'Cancelled', at: rma.cancelled_at },
  ];

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{rma.rma_number}</span>}
        subtitle={
          <div className="flex items-center gap-2">
            <Chip variant={STATUS_VARIANT[rma.status] ?? 'neutral'}>
              {rma.status_label}
            </Chip>
            <span className="text-muted">|</span>
            <span>{rma.type_label}</span>
          </div>
        }
        backTo="/return-management"
        breadcrumbs={[{ label: 'Returns', href: '/return-management' }, { label: rma.rma_number }]}
      />

      <div className="px-4 space-y-4">
        {/* Workflow Actions */}
        {actions.length > 0 && (
          <div className="flex gap-2 flex-wrap">
            {actions.map((action) => (
              <Button
                key={action.key}
                variant={action.variant === 'danger' ? 'danger' : 'primary'}
                onClick={() => handleAction(action.key)}
              >
                {action.label}
              </Button>
            ))}
          </div>
        )}

        {/* Details Panel */}
        <Panel title="RMA Details">
          <dl className="grid grid-cols-3 gap-y-3 gap-x-6 text-sm mt-2">
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Type</dt>
              <dd>{rma.type_label}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Status</dt>
              <dd><Chip variant={STATUS_VARIANT[rma.status] ?? 'neutral'}>{rma.status_label}</Chip></dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Return Date</dt>
              <dd className="font-mono tabular-nums">{formatDate(rma.return_date)}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Source</dt>
              <dd className="flex flex-col gap-0.5">
                {rma.customer && <span>Customer: {rma.customer.name}</span>}
                {rma.vendor && <span>Vendor: {rma.vendor.name}</span>}
                {rma.sales_order && (
                  <Link to={`/crm/sales-orders/${rma.sales_order.id}`} className="text-accent hover:underline font-mono">
                    SO: {rma.sales_order.so_number}
                  </Link>
                )}
                {rma.invoice && (
                  <Link to={`/accounting/invoices/${rma.invoice.id}`} className="text-accent hover:underline font-mono">
                    Invoice: {rma.invoice.invoice_number}
                  </Link>
                )}
                {rma.purchase_order && (
                  <Link to={`/purchasing/purchase-orders/${rma.purchase_order.id}`} className="text-accent hover:underline font-mono">
                    PO: {rma.purchase_order.po_number}
                  </Link>
                )}
                {rma.bill && (
                  <Link to={`/accounting/bills/${rma.bill.id}`} className="text-accent hover:underline font-mono">
                    Bill: {rma.bill.bill_number}
                  </Link>
                )}
                {!rma.customer && !rma.vendor && !rma.sales_order && !rma.invoice && !rma.purchase_order && !rma.bill && (
                  <span className="text-muted">—</span>
                )}
              </dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Reason</dt>
              <dd>{REASON_LABELS[rma.reason_code ?? ''] || rma.reason_code || '—'}</dd>
              {rma.reason_description && (
                <dd className="text-muted text-xs mt-0.5">{rma.reason_description}</dd>
              )}
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Resolution</dt>
              <dd>{RESOLUTION_LABELS[rma.resolution ?? ''] || rma.resolution || '—'}</dd>
            </div>
          </dl>

          {rma.customer_notes && (
            <div className="mt-3">
              <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Customer Notes</div>
              <div className="text-sm bg-elevated p-2 rounded">{rma.customer_notes}</div>
            </div>
          )}

          {rma.internal_notes && (
            <div className="mt-3">
              <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Internal Notes</div>
              <div className="text-sm bg-elevated p-2 rounded">{rma.internal_notes}</div>
            </div>
          )}

          {rma.refund_amount && (
            <div className="mt-3">
              <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">Refund Amount</div>
              <div className="text-sm font-medium font-mono tabular-nums">{formatPeso(rma.refund_amount)}</div>
            </div>
          )}
        </Panel>

        {/* Timeline */}
        <Panel title="Timeline">
          <div className="space-y-2 text-sm mt-2">
            {timeline
              .filter((t) => t.at)
              .map((t) => (
                <div key={t.key} className="flex items-center gap-2">
                  <div className={`h-2 w-2 rounded-full shrink-0 ${TIMELINE_DOT[t.key] ?? 'bg-muted'}`} />
                  <span className="text-muted text-xs">{t.label}</span>
                  <span className="font-mono tabular-nums">{formatDateTime(t.at)}</span>
                  {t.by && <span className="text-muted">by {t.by.name}</span>}
                </div>
              ))}
          </div>
        </Panel>

        {/* Items */}
        <Panel title={`Items (${rma.items?.length ?? 0})`}>
          {!rma.items || rma.items.length === 0 ? (
            <div className="text-muted text-sm py-2">No items.</div>
          ) : (
            <table className="w-full text-sm mt-2">
              <thead>
                <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted">
                  <th className="py-2 pr-3 font-medium">Product</th>
                  <th className="py-2 pr-3 font-medium text-right">Qty</th>
                  <th className="py-2 pr-3 font-medium text-right">Returned</th>
                  <th className="py-2 pr-3 font-medium text-right">Unit Price</th>
                  <th className="py-2 pr-3 font-medium">Condition</th>
                  <th className="py-2 pr-3 font-medium">Reason</th>
                  <th className="py-2 pr-3 font-medium">Disposition</th>
                </tr>
              </thead>
              <tbody>
                {rma.items.map((item) => (
                  <tr key={item.id} className="h-8 border-b border-subtle">
                    <td className="py-2 pr-3 font-mono">
                      {item.product
                        ? `${item.product.part_number} — ${item.product.name}`
                        : item.item
                          ? `${item.item.code} — ${item.item.name}`
                          : '—'}
                    </td>
                    <td className="py-2 pr-3 text-right font-mono tabular-nums">{formatInt(item.quantity)}</td>
                    <td className="py-2 pr-3 text-right font-mono tabular-nums">{formatInt(item.returned_quantity)}</td>
                    <td className="py-2 pr-3 text-right font-mono tabular-nums">{formatPeso(item.unit_price)}</td>
                    <td className="py-2 pr-3">{CONDITION_LABELS[item.condition ?? ''] || item.condition || '—'}</td>
                    <td className="py-2 pr-3">{item.reason || '—'}</td>
                    <td className="py-2 pr-3">
                      {item.disposition
                        ? <Chip variant={item.disposition === 'restock' ? 'success' : item.disposition === 'scrap' ? 'danger' : 'warning'}>{item.disposition.replace(/_/g, ' ')}</Chip>
                        : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      </div>

      {/* Confirm dialogs for simple actions (submit, approve, receive, inspect, cancel) */}
      {confirm && confirm !== 'complete' && CONFIRM_META[confirm] && (
        <ConfirmDialog
          isOpen
          onClose={() => setConfirm(null)}
          onConfirm={executeConfirm}
          title={CONFIRM_META[confirm].title}
          description={CONFIRM_META[confirm].description}
          confirmLabel={CONFIRM_META[confirm].label}
          variant={CONFIRM_META[confirm].variant === 'danger' ? 'danger' : 'primary'}
          pending={confirmPending}
        />
      )}

      {/* Reject dialog (requires reason) */}
      <ReasonDialog
        isOpen={rejectOpen}
        onClose={() => setRejectOpen(false)}
        onConfirm={(reason) => rejectMut.mutate(reason)}
        title="Reject this return request?"
        description="The RMA is returned to the requester with your reason. Please be specific."
        reasonLabel="Rejection reason"
        reasonPlaceholder="e.g. Items were not received within the return window"
        minLength={10}
        confirmLabel="Reject"
        variant="danger"
        pending={rejectMut.isPending}
      />

      {/* Complete with location picker */}
      <Modal
        isOpen={confirm === 'complete'}
        onClose={() => setConfirm(null)}
        title="Complete RMA"
      >
        <div className="space-y-3">
          <p className="text-sm text-muted">Select the warehouse location for stock movement:</p>
          <input
            className="input w-full"
            placeholder="Location ID (optional)"
            value={locationId}
            onChange={(e) => setLocationId(e.target.value)}
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirm(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={completeMut.isPending}
              onClick={() => completeMut.mutate(locationId || undefined)}
            >
              Confirm Complete
            </Button>
          </div>
        </div>
      </Modal>

      {/* Dispose Items Dialog */}
      {rma && showDispose && (
        <Suspense fallback={null}>
          <DisposeDialog rma={rma} isOpen={showDispose} onClose={() => setShowDispose(false)} />
        </Suspense>
      )}
    </div>
  );
}
