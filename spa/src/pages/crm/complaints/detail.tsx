/**
 * Sprint 7 — Task 68 — Customer complaint detail.
 *
 * Tabbed: Overview · 8D editor · Linked records.
 * 8D editor saves on blur (debounced via blur-then-mutate); finalising
 * the report locks all eight disciplines.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Check, Ban, FileDown, Lock } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { complaintsApi, type EightDPatch } from '@/api/crm/complaints';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { ChainHeader } from '@/components/chain';
import type { ChainStep } from '@/types/chain';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/cn';
import type { ComplaintSeverity, ComplaintStatus, EightDReport } from '@/types/crm';

const STATUS_CHIP: Record<ComplaintStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  open: 'warning', investigating: 'info', resolved: 'info', closed: 'success', cancelled: 'neutral',
};
const SEVERITY_CHIP: Record<ComplaintSeverity, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  low: 'neutral', medium: 'info', high: 'warning', critical: 'danger',
};

const D_LABELS: Array<{ key: keyof EightDPatch; title: string; helper: string }> = [
  { key: 'd1_team',              title: 'D1 — Establish the team',          helper: 'Names + roles of those investigating' },
  { key: 'd2_problem',           title: 'D2 — Describe the problem',        helper: 'What, where, when, how many, customer impact' },
  { key: 'd3_containment',       title: 'D3 — Interim containment',         helper: 'Stop the bleed: quarantine, segregate, ship-stop' },
  { key: 'd4_root_cause',        title: 'D4 — Define + verify root cause',  helper: 'Use 5-why or fishbone' },
  { key: 'd5_corrective_action', title: 'D5 — Permanent corrective action', helper: 'What change permanently removes the cause' },
  { key: 'd6_verification',      title: 'D6 — Implement + validate',        helper: 'Evidence the fix works' },
  { key: 'd7_prevention',        title: 'D7 — Prevent recurrence',          helper: 'Systemic safeguards across products / lines' },
  { key: 'd8_recognition',       title: 'D8 — Recognise the team',          helper: 'Names of contributors, lessons learned' },
];

type Tab = 'overview' | '8d' | 'linked';

export default function ComplaintDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [tab, setTab] = useState<Tab>('overview');
  const [draft, setDraft] = useState<EightDPatch>({});
  const [confirmFinalize, setConfirmFinalize] = useState(false);
  const [confirmResolve, setConfirmResolve] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'complaints', id],
    queryFn: () => complaintsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  // Hydrate the draft from the latest 8D report fetched.
  useEffect(() => {
    if (!data?.eight_d_report) return;
    const r = data.eight_d_report;
    setDraft({
      d1_team: r.d1_team ?? '',
      d2_problem: r.d2_problem ?? '',
      d3_containment: r.d3_containment ?? '',
      d4_root_cause: r.d4_root_cause ?? '',
      d5_corrective_action: r.d5_corrective_action ?? '',
      d6_verification: r.d6_verification ?? '',
      d7_prevention: r.d7_prevention ?? '',
      d8_recognition: r.d8_recognition ?? '',
    });
  }, [data?.eight_d_report?.id]);

  const save8D = useMutation({
    mutationFn: (patch: EightDPatch) => complaintsApi.update8D(id, patch),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'complaints', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Save failed'),
  });

  const finalize = useMutation({
    mutationFn: () => complaintsApi.finalize8D(id),
    onSuccess: () => {
      toast.success('8D report finalised');
      qc.invalidateQueries({ queryKey: ['crm', 'complaints', id] });
      setConfirmFinalize(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const resolveMut = useMutation({
    mutationFn: () => complaintsApi.resolve(id),
    onSuccess: () => {
      toast.success('Complaint marked resolved');
      qc.invalidateQueries({ queryKey: ['crm', 'complaints', id] });
      setConfirmResolve(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const closeMut = useMutation({
    mutationFn: () => complaintsApi.close(id),
    onSuccess: () => {
      toast.success('Complaint closed');
      qc.invalidateQueries({ queryKey: ['crm', 'complaints', id] });
      setConfirmClose(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState icon="alert-circle" title="Failed to load complaint"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
    );
  }

  const report = data.eight_d_report;
  const isFinalized = Boolean(report?.finalized_at);
  const isTerminal = data.status === 'closed' || data.status === 'cancelled';

  const complaintChain: ChainStep[] = [
    { key: 'logged',        label: 'Logged',        state: 'done', date: data.created_at?.slice(0, 10) },
    { key: 'investigating', label: 'Investigating', state: ['investigating', 'resolved', 'closed'].includes(data.status) ? 'done' : data.status === 'cancelled' ? 'pending' : 'active' },
    { key: 'resolved',      label: 'Resolved',      state: ['resolved', 'closed'].includes(data.status) ? 'done' : data.status === 'investigating' ? 'active' : 'pending' },
    { key: 'closed',        label: 'Closed',        state: data.status === 'closed' ? 'done' : 'pending', date: data.closed_at?.slice(0, 10) },
  ];
  const canManage = can('crm.complaints.manage');

  // Build LinkedRecords groups
  const linkedGroups: import('@/types/chain').LinkedGroup[] = [];
  if (data.customer) {
    linkedGroups.push({ label: 'Customer', items: [
      { id: data.customer.name, href: `/accounting/customers/${data.customer.id}` },
    ]});
  }
  if (data.product) {
    linkedGroups.push({ label: 'Product', items: [
      { id: `${data.product.part_number} — ${data.product.name}`, href: `/crm/products/${data.product.id}` },
    ]});
  }
  if (data.sales_order) {
    linkedGroups.push({ label: 'Sales order', items: [
      { id: data.sales_order.so_number, href: `/crm/sales-orders/${data.sales_order.id}` },
    ]});
  }
  if (data.ncr) {
    linkedGroups.push({ label: 'NCR', items: [
      { id: data.ncr.ncr_number, href: `/quality/ncrs/${data.ncr.id}`,
        meta: `${data.ncr.severity} · ${data.ncr.status}` },
    ]});
  }

  return (
    <div>
      <PageHeader
        title={
          <span>
            {data.complaint_number}
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">{data.status}</Chip>
            <Chip variant={SEVERITY_CHIP[data.severity]} className="ml-2">{data.severity}</Chip>
          </span>
        }
        subtitle={data.customer ? `Customer: ${data.customer.name}` : undefined}
        actions={
          <div className="flex items-center gap-2">
            {report && isFinalized && (
              <Button variant="secondary" size="sm" icon={<FileDown size={14} />}
                onClick={() => window.open(complaintsApi.pdfUrl(id), '_blank')}>
                Download 8D PDF
              </Button>
            )}
            {canManage && data.status === 'investigating' && (
              <Button variant="secondary" size="sm" onClick={() => setConfirmResolve(true)}>
                Resolve
              </Button>
            )}
            {canManage && !isTerminal && data.status === 'resolved' && (
              <Button variant="primary" size="sm" icon={<Check size={14} />} onClick={() => setConfirmClose(true)}>
                Close
              </Button>
            )}
            {canManage && !isTerminal && (
              <Button variant="secondary" size="sm" icon={<Ban size={14} />} onClick={() => setConfirmClose(true)}>
                Close
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 pt-4">
        <Panel title="Complaint flow">
          <ChainHeader steps={complaintChain} />
        </Panel>
      </div>

      {/* Tabs */}
      <div className="px-5 border-b border-default flex gap-4">
        {(['overview', '8d', 'linked'] as Tab[]).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={cn(
              'px-1 pb-2 text-xs uppercase tracking-wider transition-colors',
              tab === t
                ? 'border-b-2 border-accent text-accent font-medium'
                : 'text-muted hover:text-strong'
            )}
          >
            {t === 'overview' ? 'Overview' : t === '8d' ? '8D Report' : 'Linked records'}
          </button>
        ))}
      </div>

      <div className="px-5 py-4">
        {tab === 'overview' && (
          <div className="grid grid-cols-3 gap-4">
            <div className="col-span-2 space-y-4">
              <Panel title="Details">
                <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">Received</dt>
                    <dd className="font-mono tabular-nums">{data.received_date ?? '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">Affected qty</dt>
                    <dd className="font-mono tabular-nums">{data.affected_quantity}</dd>
                  </div>
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">Resolved</dt>
                    <dd className="font-mono tabular-nums">{data.resolved_at?.slice(0, 10) ?? '—'}</dd>
                  </div>
                  <div className="col-span-3">
                    <dt className="text-2xs uppercase tracking-wider text-muted">Customer description</dt>
                    <dd className="whitespace-pre-line">{data.description}</dd>
                  </div>
                </dl>
              </Panel>

              {data.ncr && (
                <Panel title="Linked NCR">
                  <div className="flex items-center justify-between">
                    <div>
                      <Link to={`/quality/ncrs/${data.ncr.id}`} className="font-mono text-accent hover:underline">
                        {data.ncr.ncr_number}
                      </Link>
                      <span className="ml-3 text-xs text-muted">
                        {data.ncr.severity} · {data.ncr.status}
                      </span>
                    </div>
                  </div>
                </Panel>
              )}
            </div>
            <div>
              <Panel title="Status">
                <p className="text-sm text-muted">
                  {isTerminal
                    ? `Complaint ${data.status} on ${data.closed_at?.slice(0, 10) ?? '—'}.`
                    : 'Use the 8D Report tab to drive the corrective action workflow. Resolve once D5 is verified.'}
                </p>
              </Panel>
            </div>
          </div>
        )}

        {tab === '8d' && (
          <div className="grid grid-cols-2 gap-4">
            {D_LABELS.map((d) => (
              <Panel key={d.key} title={d.title} meta={d.helper} className="col-span-1">
                <Textarea
                  rows={4}
                  disabled={isFinalized || !canManage}
                  value={(draft[d.key] as string) ?? ''}
                  onChange={(e) => setDraft((s) => ({ ...s, [d.key]: e.target.value }))}
                  onBlur={() => {
                    if (isFinalized || !canManage) return;
                    const original = ((report?.[d.key as keyof EightDReport] as string) ?? '');
                    const current = (draft[d.key] as string) ?? '';
                    if (original !== current) {
                      save8D.mutate({ [d.key]: current });
                    }
                  }}
                />
              </Panel>
            ))}

            <div className="col-span-2 flex items-center justify-end gap-2 pt-3 border-t border-default">
              {isFinalized ? (
                <span className="text-xs text-muted">
                  <Lock size={12} className="inline mr-1" />
                  Finalised on {report?.finalized_at?.slice(0, 16).replace('T', ' ')}
                </span>
              ) : canManage ? (
                <Button variant="primary" size="sm" icon={<Lock size={14} />}
                  onClick={() => setConfirmFinalize(true)}>
                  Finalise 8D
                </Button>
              ) : null}
            </div>
          </div>
        )}

        {tab === 'linked' && (
          <div className="grid grid-cols-3 gap-4">
            <div className="col-span-2">
              <Panel title="Linked records">
                <LinkedRecords groups={linkedGroups} />
              </Panel>
            </div>
            <div>
              <Panel title="Navigation">
                <Link to="/crm/complaints" className="text-xs text-accent hover:underline">
                  ← Back to complaints
                </Link>
              </Panel>
            </div>
          </div>
        )}
      </div>

      <ConfirmDialog
        isOpen={confirmFinalize}
        title="Finalise 8D report?"
        description="Once finalised, none of the 8 disciplines can be edited. The report becomes the audit-trail of record."
        confirmLabel="Finalise"
        onConfirm={() => finalize.mutate()}
        onClose={() => setConfirmFinalize(false)}
        pending={finalize.isPending}
      />
      <ConfirmDialog
        isOpen={confirmResolve}
        title="Mark complaint resolved?"
        description="Resolved complaints are still open until closed. The linked NCR remains in its current state."
        confirmLabel="Resolve"
        onConfirm={() => resolveMut.mutate()}
        onClose={() => setConfirmResolve(false)}
        pending={resolveMut.isPending}
      />
      <ConfirmDialog
        isOpen={confirmClose}
        title="Close complaint?"
        description="Closing the complaint locks it. Linked NCR closure is independent — handle that on the NCR detail page."
        confirmLabel="Close"
        onConfirm={() => closeMut.mutate()}
        onClose={() => setConfirmClose(false)}
        pending={closeMut.isPending}
      />
    </div>
  );
}
