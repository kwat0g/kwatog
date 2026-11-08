/**
 * Sprint 7 — Task 68 — Customer complaint detail.
 *
 * Tabbed: Overview · 8D editor · Linked records.
 * 8D editor saves on blur (debounced via blur-then-mutate); finalising
 * the report locks all eight disciplines.
 */
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { LuTriangleAlert, LuCheck, LuFileDown, LuLock, LuRefreshCw, LuSearch } from '@/lib/icons';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { complaintsApi, type EightDPatch } from '@/api/crm/complaints';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { Tabs } from '@/components/ui/Tabs';
import { PageHeader } from '@/components/layout/PageHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { ChainHeader } from '@/components/chain';
import type { ChainStep } from '@/types/chain';
import type { ComplaintSeverity, EightDReport } from '@/types/crm';
import { complaintStatusVariant } from '@/lib/statusVariants';
import { formatDateTime, formatDateIso } from '@/lib/formatDate';
import { useRealtimeRecord } from '@/hooks/useRealtimeRecord';
import { scheduleRealtimeChangeInvalidation } from '@/lib/realtime/invalidationMap';
import type { CustomerComplaint } from '@/types/crm';

const SEVERITY_CHIP: Record<
  ComplaintSeverity,
  'success' | 'danger' | 'warning' | 'neutral' | 'info'
> = {
  low: 'neutral',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
};

const D_LABELS: Array<{ key: keyof EightDPatch; title: string; helper: string }> = [
  {
    key: 'd1_team',
    title: 'D1 — Establish the team',
    helper: 'Names + roles of those investigating',
  },
  {
    key: 'd2_problem',
    title: 'D2 — Describe the problem',
    helper: 'What, where, when, how many, customer impact',
  },
  {
    key: 'd3_containment',
    title: 'D3 — Interim containment',
    helper: 'Stop the bleed: quarantine, segregate, ship-stop',
  },
  {
    key: 'd4_root_cause',
    title: 'D4 — Define + verify root cause',
    helper: 'Use 5-why or fishbone',
  },
  {
    key: 'd5_corrective_action',
    title: 'D5 — Permanent corrective action',
    helper: 'What change permanently removes the cause',
  },
  { key: 'd6_verification', title: 'D6 — Implement + validate', helper: 'Evidence the fix works' },
  {
    key: 'd7_prevention',
    title: 'D7 — Prevent recurrence',
    helper: 'Systemic safeguards across products / lines',
  },
  {
    key: 'd8_recognition',
    title: 'D8 — Recognise the team',
    helper: 'Names of contributors, lessons learned',
  },
];

function draftFromReport(report: EightDReport | null | undefined): EightDPatch {
  return {
    d1_team: report?.d1_team ?? '',
    d2_problem: report?.d2_problem ?? '',
    d3_containment: report?.d3_containment ?? '',
    d4_root_cause: report?.d4_root_cause ?? '',
    d5_corrective_action: report?.d5_corrective_action ?? '',
    d6_verification: report?.d6_verification ?? '',
    d7_prevention: report?.d7_prevention ?? '',
    d8_recognition: report?.d8_recognition ?? '',
  };
}

function draftsMatch(left: EightDPatch, right: EightDPatch): boolean {
  return D_LABELS.every(({ key }) => (left[key] ?? '') === (right[key] ?? ''));
}

type Tab = 'overview' | '8d' | 'linked';

export default function ComplaintDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [tab, setTab] = useState<Tab>('overview');
  const [draft, setDraft] = useState<EightDPatch>({});
  const [hasExternalChange, setHasExternalChange] = useState(false);
  const savedDraft = useRef<EightDPatch | null>(null);
  const savedVersion = useRef<string | null>(null);
  const [confirmFinalize, setConfirmFinalize] = useState(false);
  const [confirmResolve, setConfirmResolve] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);
  useRealtimeRecord('customer_complaint', id || undefined);

  const { data, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey: ['crm', 'complaints', id],
    queryFn: () => complaintsApi.show(id),
    enabled: Boolean(id),
  });
  const acceptMutationResult = (updated: CustomerComplaint) => {
    qc.setQueryData(['crm', 'complaints', id], updated);
    if (updated.eight_d_report) {
      savedDraft.current = draftFromReport(updated.eight_d_report);
      savedVersion.current = updated.eight_d_report.version ?? null;
    }
    setHasExternalChange(false);
    scheduleRealtimeChangeInvalidation(qc, { entityType: 'customer_complaint', entityId: id });
  };
  const { data: complaintOptions } = useQuery({
    queryKey: ['crm', 'complaint-options'],
    queryFn: complaintsApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const statusLabels = new Map(
    (complaintOptions?.statuses ?? []).map((option) => [option.value, option.label]),
  );
  const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');

  // Follow fresh server values when the local draft is clean. If both the
  // server report and a local edit changed, retain the draft and require review.
  useEffect(() => {
    const report = data?.eight_d_report;
    if (!report) return;

    const incoming = draftFromReport(report);
    const baseline = savedDraft.current;
    if (baseline && !draftsMatch(draft, baseline)) {
      const versionChanged = Boolean(
        report.version && savedVersion.current && report.version !== savedVersion.current,
      );
      if (versionChanged || !draftsMatch(incoming, baseline)) setHasExternalChange(true);
      return;
    }

    savedDraft.current = incoming;
    savedVersion.current = report.version ?? null;
    if (!draftsMatch(draft, incoming)) setDraft(incoming);
    setHasExternalChange(false);
  }, [data?.eight_d_report, draft]);

  const reloadLatestReport = async () => {
    const result = await refetch();
    if (result.isError || !result.data?.eight_d_report) {
      toast.error('Could not reload the latest 8D report. Your draft is still here.');
      return;
    }

    const latest = draftFromReport(result.data.eight_d_report);
    savedDraft.current = latest;
    savedVersion.current = result.data.eight_d_report.version ?? null;
    setDraft(latest);
    setHasExternalChange(false);
  };

  const save8D = useMutation({
    mutationFn: (patch: EightDPatch) => complaintsApi.update8D(id, patch, savedVersion.current),
    onSuccess: (updated) => {
      acceptMutationResult(updated);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Save failed');
      if (e.response?.status === 409) void refetch();
    },
  });

  const finalize = useMutation({
    mutationFn: () => complaintsApi.finalize8D(id),
    onSuccess: (updated) => {
      acceptMutationResult(updated);
      toast.success('8D report finalised');
      setConfirmFinalize(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const resolveMut = useMutation({
    mutationFn: () => complaintsApi.resolve(id),
    onSuccess: (updated) => {
      acceptMutationResult(updated);
      toast.success('Complaint marked resolved');
      setConfirmResolve(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const closeMut = useMutation({
    mutationFn: () => complaintsApi.close(id),
    onSuccess: (updated) => {
      acceptMutationResult(updated);
      toast.success('Complaint closed');
      setConfirmClose(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const retryNcrMut = useMutation({
    mutationFn: () => complaintsApi.retryNcr(id),
    onSuccess: (updated) => {
      acceptMutationResult(updated);
      toast.success(
        updated.ncr_handoff?.status === 'manual_required'
          ? 'NCR handoff still needs attention.'
          : 'NCR opened for complaint.',
      );
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to retry NCR handoff'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load complaint"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  const report = data.eight_d_report;
  const isFinalized = Boolean(report?.finalized_at);
  const qualityCompletionReady =
    isFinalized && data.ncr?.status === 'closed' && Boolean(data.ncr?.disposition);
  const isTerminal = data.status === 'closed' || data.status === 'cancelled';

  const complaintChain: ChainStep[] = [
    {
      key: 'logged',
      label: 'Logged',
      state: 'done',
      date: data.created_at ? formatDateIso(data.created_at) : undefined,
    },
    {
      key: 'investigating',
      label: statusLabel('investigating'),
      state: ['investigating', 'resolved', 'closed'].includes(data.status)
        ? 'done'
        : data.status === 'cancelled'
          ? 'pending'
          : 'active',
    },
    {
      key: 'resolved',
      label: statusLabel('resolved'),
      state: ['resolved', 'closed'].includes(data.status)
        ? 'done'
        : data.status === 'investigating'
          ? 'active'
          : 'pending',
    },
    {
      key: 'closed',
      label: statusLabel('closed'),
      state: data.status === 'closed' ? 'done' : 'pending',
      date: data.closed_at ? formatDateIso(data.closed_at) : undefined,
    },
  ];
  // Build LinkedRecords groups
  const linkedGroups: import('@/types/chain').LinkedGroup[] = [];
  if (data.customer) {
    linkedGroups.push({
      label: 'Customer',
      items: [{ id: data.customer.name, href: `/accounting/customers/${data.customer.id}` }],
    });
  }
  if (data.product) {
    linkedGroups.push({
      label: 'Product',
      items: [
        {
          id: `${data.product.part_number} — ${data.product.name}`,
          href: `/crm/products/${data.product.id}`,
        },
      ],
    });
  }
  if (data.sales_order) {
    linkedGroups.push({
      label: 'Sales order',
      items: [{ id: data.sales_order.so_number, href: `/crm/sales-orders/${data.sales_order.id}` }],
    });
  }
  if (data.ncr) {
    linkedGroups.push({
      label: 'NCR',
      items: [
        {
          id: data.ncr.ncr_number,
          href: `/quality/ncrs/${data.ncr.id}`,
          meta: `${data.ncr.severity_label ?? data.ncr.severity} · ${data.ncr.status_label ?? data.ncr.status}`,
        },
      ],
    });
  }

  return (
    <div>
      <PageHeader
        title={
          <span>
            {data.complaint_number}
            <Chip variant={complaintStatusVariant[data.status]} className="ml-3">
              {data.status_label ?? data.status}
            </Chip>
            <Chip variant={SEVERITY_CHIP[data.severity]} className="ml-2">
              {data.severity_label ?? data.severity}
            </Chip>
          </span>
        }
        subtitle={data.customer ? `Customer: ${data.customer.name}` : undefined}
        backTo="/crm/complaints"
        backLabel="Complaints"
        actions={
          <div className="flex items-center gap-2">
            {data.available_actions?.retry_ncr_handoff && (
              <Button
                variant="secondary"
                size="sm"
                icon={
                  <LuRefreshCw size={14} className={retryNcrMut.isPending ? 'animate-spin' : ''} />
                }
                loading={retryNcrMut.isPending}
                onClick={() => retryNcrMut.mutate()}
              >
                Retry NCR handoff
              </Button>
            )}
            <Link to="/quality/traceability">
              <Button variant="secondary" size="sm" icon={<LuSearch size={14} />}>
                Trace
              </Button>
            </Link>
            {report && isFinalized && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuFileDown size={14} />}
                onClick={() =>
                  void downloadAuthenticatedFile(complaintsApi.pdfUrl(id), {
                    openInNewTab: true,
                    errorMessage: 'Failed to generate 8D report PDF.',
                  })
                }
              >
                Download 8D PDF
              </Button>
            )}
            {data.available_actions?.resolve && (
              <Button variant="secondary" size="sm" onClick={() => setConfirmResolve(true)}>
                Resolve
              </Button>
            )}
            {data.available_actions?.close && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuCheck size={14} />}
                onClick={() => setConfirmClose(true)}
              >
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
      <Tabs
        className="px-5"
        label="Complaint sections"
        value={tab}
        onChange={setTab}
        items={[
          { key: 'overview', label: 'Overview' },
          { key: '8d', label: '8D report' },
          { key: 'linked', label: 'Linked records' },
        ]}
      />

      <div className="px-5 py-4">
        {tab === 'overview' && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div className="col-span-2 space-y-4">
              <Panel title="Details">
                <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 text-sm">
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
                    <dd className="font-mono tabular-nums">{formatDateIso(data.resolved_at)}</dd>
                  </div>
                  <div className="col-span-3">
                    <dt className="text-2xs uppercase tracking-wider text-muted">
                      Customer description
                    </dt>
                    <dd className="whitespace-pre-line">{data.description}</dd>
                  </div>
                </dl>
              </Panel>

              {data.ncr_handoff?.status === 'manual_required' && (
                <div className="flex items-start gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
                  <LuTriangleAlert size={16} className="mt-0.5 shrink-0 text-warning-fg" />
                  <div className="flex-1">
                    <div className="font-medium">Quality NCR handoff needs attention</div>
                    <div className="text-muted">
                      {data.ncr_handoff.message ||
                        'The complaint cannot be resolved or closed until an NCR is linked.'}
                    </div>
                  </div>
                  {data.available_actions?.retry_ncr_handoff && (
                    <Button
                      variant="secondary"
                      size="sm"
                      icon={<LuRefreshCw size={13} />}
                      loading={retryNcrMut.isPending}
                      onClick={() => retryNcrMut.mutate()}
                    >
                      Retry
                    </Button>
                  )}
                </div>
              )}

              {data.ncr && (
                <Panel title="Linked NCR">
                  <div className="flex items-center justify-between">
                    <div>
                      <Link
                        to={`/quality/ncrs/${data.ncr.id}`}
                        className="font-mono text-accent hover:underline"
                      >
                        {data.ncr.ncr_number}
                      </Link>
                      <span className="ml-3 flex flex-wrap items-center gap-2 text-xs text-muted">
                        {data.ncr.severity_label ?? data.ncr.severity}
                        <Chip variant={chipVariantForStatus(data.ncr.status)}>
                          {data.ncr.status_label ?? data.ncr.status}
                        </Chip>
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
                    ? `Complaint ${data.status_label ?? data.status} on ${formatDateIso(data.closed_at)}.`
                    : qualityCompletionReady
                      ? 'The finalized 8D report and closed NCR are ready for the next complaint lifecycle step.'
                      : 'Finalize all eight 8D disciplines and close the linked NCR with a disposition before resolving or closing this complaint.'}
                </p>
              </Panel>
            </div>
          </div>
        )}

        {tab === '8d' && (
          <div className="grid grid-cols-2 gap-4">
            {hasExternalChange && (
              <div role="status" className="col-span-2 flex items-center justify-between gap-3 rounded-md border border-warning/40 bg-warning-bg/10 px-4 py-3 text-sm">
                <p className="text-muted">
                  This 8D report changed while you had unsaved edits. Your draft is preserved; reload the latest report before editing again.
                </p>
                <Button variant="secondary" size="sm" disabled={isFetching} onClick={() => void reloadLatestReport()}>
                  Reload latest
                </Button>
              </div>
            )}
            {D_LABELS.map((d) => (
              <Panel key={d.key} title={d.title} meta={d.helper} className="col-span-1">
                <Textarea
                  rows={4}
                  disabled={!data.available_actions?.edit_8d || isFinalized || hasExternalChange || save8D.isPending}
                  value={(draft[d.key] as string) ?? ''}
                  onChange={(e) => setDraft((s) => ({ ...s, [d.key]: e.target.value }))}
                  onBlur={() => {
                    if (!data.available_actions?.edit_8d || isFinalized || hasExternalChange || save8D.isPending) return;
                    const baseline = savedDraft.current;
                    const serverDraft = draftFromReport(report);
                    const versionChanged = Boolean(
                      report?.version && savedVersion.current && report.version !== savedVersion.current,
                    );
                    if (
                      baseline &&
                      !draftsMatch(draft, baseline) &&
                      (versionChanged || !draftsMatch(serverDraft, baseline))
                    ) {
                      setHasExternalChange(true);
                      return;
                    }
                    const original = (report?.[d.key as keyof EightDReport] as string) ?? '';
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
                  <LuLock size={12} className="inline mr-1" />
                  Finalised on {formatDateTime(report?.finalized_at)}
                </span>
              ) : data.available_actions?.finalize_8d ? (
                <Button
                  variant="primary"
                  size="sm"
                  icon={<LuLock size={14} />}
                  onClick={() => setConfirmFinalize(true)}
                  disabled={hasExternalChange}
                >
                  Finalise 8D
                </Button>
              ) : null}
            </div>
          </div>
        )}

        {tab === 'linked' && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
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
        onConfirm={() => {
          if (!hasExternalChange) finalize.mutate();
        }}
        onClose={() => setConfirmFinalize(false)}
        pending={finalize.isPending}
        disabled={hasExternalChange}
      />
      <ConfirmDialog
        isOpen={confirmResolve}
        title="Mark complaint resolved?"
        description="The finalized 8D report and closed linked NCR are the authoritative quality completion gate for this complaint."
        confirmLabel="Resolve"
        onConfirm={() => resolveMut.mutate()}
        onClose={() => setConfirmResolve(false)}
        pending={resolveMut.isPending}
      />
      <ConfirmDialog
        isOpen={confirmClose}
        title="Close complaint?"
        description="Closing the complaint records the completed quality investigation and locks the commercial lifecycle."
        confirmLabel="Close"
        onConfirm={() => closeMut.mutate()}
        onClose={() => setConfirmClose(false)}
        pending={closeMut.isPending}
      />
    </div>
  );
}
