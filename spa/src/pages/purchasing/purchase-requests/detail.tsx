import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Send, ThumbsUp, ThumbsDown, X, ShoppingCart } from 'lucide-react';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ChainHeader } from '@/components/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { ApprovalRecord, PurchaseRequest, PurchaseRequestStatus } from '@/types/purchasing';
import type { ChainStep } from '@/types/chain';

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

const statusVariant: Record<PurchaseRequestStatus, 'neutral' | 'warning' | 'info' | 'success' | 'danger'> = {
  draft: 'neutral', pending: 'info', approved: 'success', rejected: 'danger',
  converted: 'neutral', cancelled: 'neutral',
};

export default function PurchaseRequestDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const nav = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();

  const [confirm, setConfirm] = useState<'submit' | 'approve' | 'cancel' | null>(null);
  const [rejectOpen, setRejectOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'purchase-requests', id],
    queryFn: () => purchaseRequestsApi.show(id),
    enabled: !!id,
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests', id] });

  const submit = useMutation({
    mutationFn: () => purchaseRequestsApi.submit(id),
    onSuccess: () => { invalidate(); toast.success('Submitted for approval.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to submit.')),
  });
  const approve = useMutation({
    mutationFn: () => purchaseRequestsApi.approve(id),
    onSuccess: () => { invalidate(); toast.success('Purchase request approved.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to approve.')),
  });
  const reject = useMutation({
    mutationFn: (reason: string) => purchaseRequestsApi.reject(id, reason),
    onSuccess: () => { invalidate(); toast.success('Purchase request rejected.'); setRejectOpen(false); },
    onError: (e) => toast.error(errMsg(e, 'Failed to reject.')),
  });
  const cancel = useMutation({
    mutationFn: () => purchaseRequestsApi.cancel(id),
    onSuccess: () => { invalidate(); toast.success('Purchase request cancelled.'); setConfirm(null); },
    onError: (e) => toast.error(errMsg(e, 'Failed to cancel.')),
  });

  if (isLoading) return <SkeletonTable rows={6} columns={5} />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load PR" action={<Button onClick={() => refetch()}>Retry</Button>} />
  );

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{data.pr_number}</span>}
        backTo="/purchasing/purchase-requests" backLabel="Purchase requests"
        actions={
          <div className="flex items-center gap-2">
            <Chip variant={statusVariant[data.status]}>{data.status}</Chip>
            {data.is_auto_generated && <Chip variant="warning">AUTO</Chip>}
            {data.status === 'draft' && can('purchasing.pr.create') && (
              <Button size="sm" variant="primary" icon={<Send size={14} />} onClick={() => setConfirm('submit')} loading={submit.isPending}>Submit</Button>
            )}
            {data.status === 'pending' && can('purchasing.pr.approve') && (
              <>
                <Button size="sm" variant="secondary" icon={<ThumbsDown size={14} />} onClick={() => setRejectOpen(true)} loading={reject.isPending}>Reject</Button>
                <Button size="sm" variant="primary" icon={<ThumbsUp size={14} />} onClick={() => setConfirm('approve')} loading={approve.isPending}>Approve</Button>
              </>
            )}
            {data.status === 'approved' && can('purchasing.po.create') && (
              <Button size="sm" variant="primary" icon={<ShoppingCart size={14} />} onClick={() => nav(`/purchasing/purchase-orders/create?pr_id=${data.id}`)}>Convert to PO</Button>
            )}
            {(data.status === 'draft' || data.status === 'pending') && (
              <Button size="sm" variant="secondary" icon={<X size={14} />} onClick={() => setConfirm('cancel')} loading={cancel.isPending}>Cancel</Button>
            )}
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        <Panel title="Approval chain">
          <ChainHeader steps={buildPrChainSteps(data)} />
        </Panel>
      </div>
      <div className="px-5 grid grid-cols-3 gap-4 pb-6">
        <div className="col-span-2 space-y-4">
          <Panel title="Header">
            <dl className="grid grid-cols-3 gap-y-3 gap-x-6 text-sm">
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Date</dt><dd className="font-mono">{formatDate(data.date)}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Priority</dt><dd>{data.priority}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Department</dt><dd>{data.department?.name ?? '—'}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Requester</dt><dd>{data.requester?.name ?? '—'}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted">Total estimate</dt><dd className="font-mono tabular-nums">{formatPeso(data.total_estimated_amount)}</dd></div>
              {data.reason && <div className="col-span-3"><dt className="text-2xs uppercase tracking-wider text-muted">Reason</dt><dd>{data.reason}</dd></div>}
            </dl>
          </Panel>
          <Panel title="Line items">
            <table className="w-full text-xs">
              <thead><tr className="text-2xs uppercase tracking-wider text-muted">
                <th className="text-left py-1">Item</th>
                <th>Description</th>
                <th className="text-right">Qty</th>
                <th>Unit</th>
                <th className="text-right">Est. price</th>
                <th className="text-right">Total</th>
              </tr></thead>
              <tbody>
                {data.items?.map((l) => (
                  <tr key={l.id} className="h-8 border-t border-subtle">
                    <td className="font-mono">{l.item?.code ?? '—'}</td>
                    <td>{l.description}</td>
                    <td className="text-right font-mono tabular-nums">{Number(l.quantity).toFixed(2)}</td>
                    <td>{l.unit}</td>
                    <td className="text-right font-mono tabular-nums">{l.estimated_unit_price ? Number(l.estimated_unit_price).toFixed(2) : '—'}</td>
                    <td className="text-right font-mono tabular-nums font-medium">{l.estimated_total}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
        </div>
        <div className="space-y-4">
          <Panel title="Approval chain">
            <ApprovalChain records={data.approval_records ?? []} />
          </Panel>
          {data.purchase_orders && data.purchase_orders.length > 0 && (
            <Panel title="Linked POs">
              <ul className="text-xs divide-y divide-subtle">
                {data.purchase_orders.map((po) => (
                  <li key={po.id} className="py-2">
                    <Link to={`/purchasing/purchase-orders/${po.id}`} className="font-mono text-accent">{po.po_number}</Link>
                    <div className="text-muted">{po.vendor?.name} · {formatPeso(po.total_amount)}</div>
                    <Chip variant="info">{po.status.replace('_', ' ')}</Chip>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </div>
      </div>

      <ConfirmDialog
        isOpen={confirm === 'submit'}
        onClose={() => setConfirm(null)}
        onConfirm={() => submit.mutate()}
        title="Submit for approval?"
        description={<>Once submitted, the PR enters the approval workflow and edits are no longer allowed.</>}
        confirmLabel="Submit"
        variant="primary"
        pending={submit.isPending}
      />
      <ConfirmDialog
        isOpen={confirm === 'approve'}
        onClose={() => setConfirm(null)}
        onConfirm={() => approve.mutate()}
        title="Approve this PR?"
        description={<>Approving advances the workflow. The action is recorded against your account in the audit log.</>}
        confirmLabel="Approve"
        variant="primary"
        pending={approve.isPending}
      />
      <ConfirmDialog
        isOpen={confirm === 'cancel'}
        onClose={() => setConfirm(null)}
        onConfirm={() => cancel.mutate()}
        title="Cancel this PR?"
        description={<>Cancellation is permanent. A cancelled PR cannot be re-submitted.</>}
        confirmLabel="Yes, cancel PR"
        cancelLabel="Keep PR"
        variant="danger"
        pending={cancel.isPending}
      />
      <ReasonDialog
        isOpen={rejectOpen}
        onClose={() => setRejectOpen(false)}
        onConfirm={(reason) => reject.mutate(reason)}
        title="Reject this PR?"
        description="Rejection is recorded in the approval workflow. Provide a clear reason for the requester."
        reasonLabel="Rejection reason"
        reasonPlaceholder="e.g. Budget exceeded, please re-scope and re-submit"
        minLength={10}
        confirmLabel="Reject"
        variant="danger"
        pending={reject.isPending}
      />
    </div>
  );
}

/** PR chain: Draft → Submitted → each approval step → Approved → Converted. */
function buildPrChainSteps(pr: PurchaseRequest): ChainStep[] {
  const steps: ChainStep[] = [
    { key: 'draft', label: 'Draft', date: formatDate(pr.date),
      state: pr.status === 'draft' ? 'active' : 'done' },
    { key: 'submit', label: 'Submitted', date: pr.submitted_at ? formatDate(pr.submitted_at) : undefined,
      state: pr.submitted_at ? 'done' : pr.status === 'draft' ? 'pending' : 'pending' },
  ];
  for (const r of (pr.approval_records ?? [])) {
    steps.push({
      key: `step-${r.step_order}`,
      label: r.role_slug.replace(/_/g, ' '),
      date: r.acted_at ? new Date(r.acted_at).toLocaleDateString() : undefined,
      state: r.action === 'approved' ? 'done' : r.action === 'pending' ? 'active' : 'pending',
    });
  }
  steps.push({
    key: 'approved', label: 'Approved',
    date: pr.approved_at ? formatDate(pr.approved_at) : undefined,
    state: pr.status === 'approved' ? 'active' : pr.status === 'converted' ? 'done' : 'pending',
  });
  steps.push({
    key: 'converted', label: 'Converted to PO',
    state: pr.status === 'converted' ? 'done' : 'pending',
  });
  return steps;
}

function ApprovalChain({ records }: { records: ApprovalRecord[] }) {
  if (records.length === 0) return <div className="text-sm text-muted">No approval workflow yet.</div>;
  return (
    <ol className="text-xs space-y-2">
      {records.map((r) => (
        <li key={r.step_order} className="flex items-start gap-2">
          <span className={
            'w-2 h-2 rounded-full mt-1.5 ' +
            (r.action === 'approved' ? 'bg-success' : r.action === 'rejected' ? 'bg-danger' : r.action === 'skipped' ? 'bg-elevated' : 'bg-warning')
          } />
          <div className="flex-1">
            <div className="font-medium">Step {r.step_order} — {r.role_slug.replace(/_/g, ' ')}</div>
            <div className="text-muted">
              <Chip variant={r.action === 'approved' ? 'success' : r.action === 'rejected' ? 'danger' : r.action === 'skipped' ? 'neutral' : 'warning'}>{r.action}</Chip>
              {r.acted_at && <span className="ml-2 font-mono">{new Date(r.acted_at).toLocaleString()}</span>}
            </div>
            {r.remarks && <div className="text-muted italic mt-1">"{r.remarks}"</div>}
          </div>
        </li>
      ))}
    </ol>
  );
}
