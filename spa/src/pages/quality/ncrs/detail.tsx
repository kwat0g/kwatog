/**
 * Sprint 7 — Task 64 — NCR detail page.
 *
 * Shows the NCR root row, lets QC append actions (containment / corrective
 * / preventive), set a final disposition, and close the NCR. Replacement
 * work orders auto-created on close are surfaced in the LinkedRecords panel.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { LuCheck, LuBan, LuPlus, LuArrowLeft, LuArrowRight } from '@/lib/icons';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { client } from '@/api/client';
import type { DefectType } from '@/types/production';
import { ncrsApi } from '@/api/quality/ncrs';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Select } from '@/components/ui/Select';
import { Input } from '@/components/ui/Input';
import { Checkbox } from '@/components/ui/Checkbox';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { ActivityStream } from '@/components/chain/ActivityStream';
import { ChainHeader } from '@/components/chain';
import { buildNcrChain } from '@/lib/chains';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import { formatQuantity } from '@/lib/formatNumber';
import type {
  NcrActionType,
  NcrDisposition,
  NcrSeverity,
  NcrStatus,
  NcrAction,
  EffectivenessStatus,
} from '@/types/quality';

const STATUS_CHIP: Record<NcrStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  open: 'warning',
  in_progress: 'info',
  closed: 'success',
  cancelled: 'neutral',
};

const SEVERITY_CHIP: Record<NcrSeverity, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  low: 'neutral',
  medium: 'warning',
  high: 'danger',
  critical: 'danger',
};

export default function NcrDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManageNcr = can('quality.ncr.manage');
  const [actionType, setActionType] = useState<NcrActionType>('containment');
  const [actionDesc, setActionDesc] = useState('');
  const [ownerId, setOwnerId] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [disposition, setDisposition] = useState<NcrDisposition | ''>('');
  const [enteredAcceptedQuantity, setMrbAcceptedQuantity] = useState<string | null>(null);
  const [trialScrap, setTrialScrap] = useState('');
  const [trialDefects, setTrialDefects] = useState<
    Array<{ defect_type_id: string; count: string }>
  >([]);
  const [sortingQuantity, setSortingQuantity] = useState('');
  const [sortingReference, setSortingReference] = useState('');
  const [concessionReference, setConcessionReference] = useState('');
  const [enteredSortingPerformer, setSortingPerformer] = useState<string | null>(null);
  const [recoveryPassed, setRecoveryPassed] = useState(false);
  const [defectsSegregated, setDefectsSegregated] = useState(false);
  const [rootCause, setRootCause] = useState('');
  const [correctiveAction, setCorrectiveAction] = useState('');
  const [confirmClose, setConfirmClose] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'ncrs', id],
    queryFn: () => ncrsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
    // Another role (QC review, MRB, containment) can move this record while
    // we watch it. Poll while open so cross-user changes appear without a
    // manual refresh — same precedent as the sales-order detail page.
    refetchInterval: (query) =>
      query.state.data && !['closed', 'cancelled'].includes(query.state.data.status)
        ? 15_000
        : false,
  });
  const { data: options } = useQuery({
    queryKey: ['quality', 'ncrs', 'options'],
    queryFn: () => ncrsApi.options(),
  });
  const { data: assignees = [] } = useQuery({
    queryKey: ['quality', 'ncr-assignees'],
    queryFn: ncrsApi.assignees,
    enabled:
      canManageNcr ||
      Boolean(data?.is_incoming_mrb && !data?.mrb_decided_at && can('quality.inspections.review')),
    staleTime: 5 * 60 * 1000,
  });
  const trialRecovery = Boolean(
    data?.inspection?.stage === 'in_process' && (data.startup_trial || data.historical_held),
  );
  const trialDefectOptions = useQuery({
    queryKey: ['quality', 'defect-types'],
    queryFn: () =>
      client
        .get<{ data: DefectType[] }>('/quality/defect-types')
        .then((response) => response.data.data),
    enabled: trialRecovery && can('quality.ncr.manage'),
  });
  const labelFor = (
    items: Array<{ value: string; label: string }> | undefined,
    value: string | null | undefined,
  ) =>
    (value && items?.find((item) => item.value === value)?.label) ??
    value?.replace('_', ' ') ??
    '—';

  const retryQuarantine = useMutation({
    mutationFn: () => ncrsApi.retryQuarantine(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'mrb'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock-levels'] });
      toast.success('Missing quarantine holds recovered.');
    },
    onError: (error: AxiosError<{ message?: string }>) =>
      toast.error(error.response?.data?.message ?? 'Quarantine recovery failed.'),
  });

  const addAction = useMutation({
    mutationFn: () =>
      ncrsApi.addAction(id, {
        action_type: actionType,
        description: actionDesc,
        ...(ownerId ? { owner_id: ownerId } : {}),
        ...(dueDate ? { due_date: dueDate } : {}),
      }),
    onSuccess: () => {
      toast.success('Action added');
      setActionDesc('');
      setOwnerId('');
      setDueDate('');
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Could not add action. Try again.'),
  });

  // Once the MRB decision settled the receipt, the disposition is fixed; only
  // root cause / corrective action remain editable (the API enforces this).
  const mrbLocked = Boolean(data?.mrb_decided_at);
  const effectiveDisposition = mrbLocked ? (data?.disposition ?? '') : disposition;
  const fullBatch = Boolean(data?.full_piece_inspection && data.inspection?.stage === 'outgoing');
  const reportedDefects = data?.inspection?.sample_defect_count;
  const conformingQuantity = fullBatch && reportedDefects != null
    ? Math.max(0, Number(data?.inspection?.batch_quantity ?? 0) - reportedDefects)
    : null;
  const repairBatch = fullBatch && effectiveDisposition === 'rework' && !mrbLocked;
  const repairNotes = fullBatch && effectiveDisposition === 'rework';

  const recoveryQuantity = Number(
    data?.process_recovery_quantity ??
      data?.sorting_required_quantity ??
      data?.inspection?.batch_quantity ??
      data?.affected_quantity ??
      0,
  );
  const mrbAcceptedQuantity = enteredAcceptedQuantity ??
    (fullBatch && conformingQuantity !== null ? String(Math.min(conformingQuantity, recoveryQuantity)) : '');
  const sortingPerformer = enteredSortingPerformer ??
    (fullBatch ? assignees.find((person) => person.id === data?.inspection?.inspector?.id)?.id ?? '' : '');
  const countable = Boolean(
    data?.startup_trial ||
    data?.historical_held ||
    data?.is_outgoing_recovery ||
    data?.inspection?.sampling_basis === 'piece' ||
    data?.inspection?.source_uom_code?.trim().toUpperCase() === 'PCS',
  );
  const canKeepGood =
    Boolean(data?.is_incoming_mrb || data?.is_outgoing_recovery || data?.is_process_recovery) &&
    (effectiveDisposition === 'scrap' ||
      effectiveDisposition === 'return_to_supplier' ||
      ((data?.is_outgoing_recovery || data?.is_process_recovery) &&
        effectiveDisposition === 'rework'));
  const positiveGood = canKeepGood && Number(mrbAcceptedQuantity) > 0;
  const coveredQuantity = fullBatch ? String(recoveryQuantity) : sortingQuantity;
  const needsSortingEvidence =
    !mrbLocked &&
    Boolean(data?.is_incoming_mrb || data?.is_outgoing_recovery || data?.is_process_recovery) &&
    (positiveGood ||
      (data?.is_outgoing_recovery && effectiveDisposition === 'rework') ||
      data?.is_process_recovery ||
      (data?.is_incoming_mrb && effectiveDisposition === 'use_as_is'));
  const goodQuantityValid =
    mrbLocked ||
    !canKeepGood ||
    (!repairBatch && !trialRecovery && mrbAcceptedQuantity === '') ||
    (/^\d+(?:\.\d{1,3})?$/.test(mrbAcceptedQuantity) &&
      Number(mrbAcceptedQuantity) >= 0 &&
      (conformingQuantity === null || Number(mrbAcceptedQuantity) <= conformingQuantity) &&
      (!countable || Number.isInteger(Number(mrbAcceptedQuantity))) &&
      ((data?.is_outgoing_recovery || data?.is_process_recovery) &&
      effectiveDisposition === 'rework'
        ? Number(mrbAcceptedQuantity) <= recoveryQuantity
        : Number(mrbAcceptedQuantity) < recoveryQuantity));
  const trialDispositionValid =
    mrbLocked ||
    !trialRecovery ||
    (/^\d+$/.test(mrbAcceptedQuantity) &&
      /^\d+$/.test(trialScrap) &&
      Number(mrbAcceptedQuantity) + Number(trialScrap) === recoveryQuantity &&
      trialDefects.every(
        (row) => row.defect_type_id && /^\d+$/.test(row.count) && Number(row.count) > 0,
      ) &&
      trialDefects.reduce((total, row) => total + Number(row.count), 0) === Number(trialScrap));
  const sortingValid =
    !needsSortingEvidence ||
    (Number(coveredQuantity) === recoveryQuantity &&
      Number(coveredQuantity) > 0 &&
      Boolean(sortingReference.trim()) &&
      Boolean(sortingPerformer) &&
      recoveryPassed && (!data?.full_piece_inspection || defectsSegregated));
  const needsConcession = needsSortingEvidence && effectiveDisposition === 'use_as_is';
  const concessionValid =
    !needsConcession ||
    (Boolean(concessionReference.trim()) && concessionReference.trim() !== sortingReference.trim());
  const criticalConcession = data?.severity === 'critical' && effectiveDisposition === 'use_as_is';

  const setDispositionMut = useMutation({
    mutationFn: () =>
      ncrsApi.setDisposition(id, {
        disposition: effectiveDisposition as NcrDisposition,
        root_cause: rootCause || undefined,
        corrective_action: correctiveAction || undefined,
        mrb_accepted_quantity:
          !mrbLocked &&
          canKeepGood &&
          (!data?.is_outgoing_recovery || positiveGood || effectiveDisposition === 'rework')
            ? mrbAcceptedQuantity || undefined
            : undefined,
        ...(needsSortingEvidence
          ? {
              sorting_evidence: {
                inspected_quantity: coveredQuantity,
                evidence_reference: sortingReference.trim(),
                concession_approval_reference: needsConcession
                  ? concessionReference.trim()
                  : undefined,
                performed_by: sortingPerformer,
                recovery_passed: recoveryPassed,
                ...(data?.full_piece_inspection ? { defects_segregated: defectsSegregated } : {}),
                ...(trialRecovery
                  ? {
                      trial_scrapped_quantity: Number(trialScrap),
                      trial_defects: trialDefects.map((row) => ({
                        defect_type_id: row.defect_type_id,
                        count: Number(row.count),
                      })),
                    }
                  : {}),
              },
            }
          : {}),
      }),
    onSuccess: () => {
      toast.success('Disposition saved');
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Could not save disposition. Try again.'),
  });

  const close = useMutation({
    mutationFn: () => ncrsApi.close(id),
    onSuccess: (ncr) => {
      toast.success(
        ncr.replacement_work_order
          ? `NCR closed; replacement WO ${ncr.replacement_work_order.wo_number} created`
          : 'NCR closed',
      );
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
      setConfirmClose(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Could not close'),
  });

  const cancel = useMutation({
    mutationFn: (reason: string) => ncrsApi.cancel(id, reason),
    onSuccess: () => {
      toast.success('NCR cancelled');
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
      setConfirmCancel(false);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Could not cancel'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load NCR"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  const isTerminal = ['closed', 'cancelled'].includes(data.status);
  const reviewerOnly =
    !canManageNcr &&
    can('quality.inspections.review') &&
    data.is_incoming_mrb &&
    !mrbLocked &&
    !isTerminal;

  const ncrChain = buildNcrChain(data).map((step) => fullBatch && step.key === 'actions' ? {
    ...step,
    label: repairNotes ? 'Repair and QC' : 'Defect handling',
    state: data.status === 'closed' ? 'done' as const : data.disposition ? 'active' as const : 'pending' as const,
  } : step);

  // Build LinkedRecords groups
  const linkedGroups: import('@/types/chain').LinkedGroup[] = [];
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
  if (data.inspection) {
    linkedGroups.push({
      label: 'Inspection',
      items: [
        {
          id: data.inspection.inspection_number,
          href: `/quality/inspections/${data.inspection.id}`,
          meta: `${data.inspection.stage_label ?? data.inspection.stage} · ${data.inspection.status_label ?? data.inspection.status}`,
        },
      ],
    });
  }
  if (data.rework_work_order) {
    linkedGroups.push({
      label: data.is_inline_rework ? 'Rework in source work order' : 'Repair work order',
      items: [
        {
          id: data.rework_work_order.wo_number,
          href: `/production/work-orders/${data.rework_work_order.id}`,
          meta: `${data.is_inline_rework ? data.confirmed_rework_quantity : data.rework_work_order.quantity_target} pcs · ${data.rework_work_order.status_label ?? data.rework_work_order.status}`,
        },
      ],
    });
  }
  if (data.replacement_work_order) {
    linkedGroups.push({
      label: 'Replacement WO',
      items: [
        {
          id: data.replacement_work_order.wo_number,
          href: `/production/work-orders/${data.replacement_work_order.id}`,
          meta: `${data.replacement_work_order.quantity_target} pcs · ${data.replacement_work_order.status_label ?? data.replacement_work_order.status}`,
        },
      ],
    });
  }

  const ACTION_DOT: Record<NcrActionType, 'success' | 'warning' | 'info'> = {
    containment: 'warning',
    corrective: 'info',
    preventive: 'success',
  };
  const activityItems: import('@/types/chain').ActivityItem[] = (data.actions ?? []).map((a) => ({
    dot: ACTION_DOT[a.action_type],
    text: (
      <span>
        <span className="font-medium uppercase text-2xs tracking-wider text-muted">
          {a.action_type_label ?? a.action_type}
        </span>
        <span className="ml-2">{a.description}</span>
        <span className="ml-2 text-muted">— {a.performer?.name ?? 'system'}</span>
      </span>
    ),
    time: formatDateTime(a.performed_at) ?? '',
  }));
  const capaActions = (data.actions ?? []).filter(
    (action) => action.action_type === 'corrective' || action.action_type === 'preventive',
  );

  return (
    <div>
      <PageHeader
        backTo="/quality/ncrs"
        backLabel="Back to NCRs"
        title={
          <span>
            {data.ncr_number}
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">
              {labelFor(options?.statuses, data.status)}
            </Chip>
            <Chip variant={SEVERITY_CHIP[data.severity]} className="ml-2">
              {labelFor(options?.severities, data.severity)}
            </Chip>
            {data.is_auto_generated && (
              <span title="Auto-generated from inspection failure" className="ml-2 inline-block">
                <Chip variant="info">Auto</Chip>
              </span>
            )}
          </span>
        }
        subtitle={
          data.product
            ? `${data.product.part_number} — ${data.product.name}`
            : labelFor(options?.sources, data.source)
        }
        actions={
          <div className="flex items-center gap-2">
            {!isTerminal && data.disposition && can('quality.ncr.manage') && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuCheck size={14} />}
                onClick={() => setConfirmClose(true)}
              >
                Close NCR
              </Button>
            )}
            {!isTerminal && can('quality.ncr.manage') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuBan size={14} />}
                onClick={() => setConfirmCancel(true)}
              >
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4">
        <Panel title="Quality flow">
          <ChainHeader steps={ncrChain} />
        </Panel>
      </div>

      <div className="grid grid-cols-1 gap-4 px-5 pb-5 lg:grid-cols-3">
        <div className="min-w-0 space-y-4 lg:col-span-2">
          {fullBatch && !isTerminal && (
            <div className="rounded-md border border-default bg-surface px-4 py-3 text-sm">
              <p className="font-medium">
                {mrbLocked ? 'Release decision recorded.' : 'Next: separate defective pieces and approve stock release.'}
              </p>
              <p className="mt-1 text-xs text-muted">
                {mrbLocked
                  ? 'Complete defect handling and approved QC before closing this NCR.'
                  : 'After inspection review, QC can confirm separation and release conforming pieces.'}
              </p>
            </div>
          )}
          {(data.is_outgoing_recovery || fullBatch) &&
            data.sorting_approved_at &&
            Number(data.inspection?.accepted_quantity ?? 0) > 0 && (
              <Panel title="Approved stock release">
                <p className="text-sm">
                  {formatQuantity(data.inspection?.accepted_quantity ?? 0)} pieces are approved for
                  release. The failed inspection remains part of the history.
                </p>
                {can('supply_chain.deliveries.view') && (
                  <Link
                    className="mt-2 inline-block text-sm text-accent hover:underline"
                    to="/supply-chain/deliveries"
                  >
                    Review delivery drafts
                  </Link>
                )}
              </Panel>
            )}
          <Panel title="Details">
            <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 text-sm [&>div]:min-w-0 [&_dd]:break-words">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Source</dt>
                <dd>{labelFor(options?.sources, data.source)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">
                  {fullBatch ? 'Batch quantity' : data.is_process_recovery ? 'Required process sample' : 'Held quantity'}
                </dt>
                <dd className="font-mono tabular-nums">
                  {formatQuantity(recoveryQuantity)}
                  {countable ? ' pcs' : data.inspection?.source_uom_code ? ` ${data.inspection.source_uom_code}` : ''}
                </dd>
              </div>
              {fullBatch && reportedDefects != null && (
                <>
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">Defective pieces</dt>
                    <dd className="font-mono tabular-nums text-danger-fg">{formatQuantity(reportedDefects)} pcs</dd>
                  </div>
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">
                      {mrbLocked ? 'Conforming pieces' : 'Conforming · awaiting release'}
                    </dt>
                    <dd className="font-mono tabular-nums">{formatQuantity(conformingQuantity ?? 0)} pcs</dd>
                  </div>
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">Stock hold</dt>
                    <dd>{mrbLocked ? 'Release decision recorded' : 'Entire batch held'}</dd>
                  </div>
                </>
              )}
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Disposition</dt>
                <dd>{labelFor(options?.dispositions, data.disposition)}</dd>
              </div>
              {data.mrb_decided_at && (
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted">MRB decision</dt>
                  <dd>
                    {formatDateTime(data.mrb_decided_at)} by {data.mrb_decider?.name ?? '—'}
                  </dd>
                </div>
              )}
              {data.mrb_accepted_quantity != null && (
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted">
                    Verified good quantity
                  </dt>
                  <dd className="font-mono tabular-nums">{data.mrb_accepted_quantity}</dd>
                </div>
              )}
              {data.mrb_accepted_quantity != null && (
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted">
                    Remainder for disposition
                  </dt>
                  <dd className="font-mono tabular-nums">
                    {formatQuantity(recoveryQuantity - Number(data.mrb_accepted_quantity))}
                  </dd>
                </div>
              )}
              {data.sorting_evidence_reference && (
                <div className="col-span-full">
                  <dt className="text-2xs uppercase tracking-wider text-muted">
                    Sorting / reinspection evidence
                  </dt>
                  <dd className="break-words">{data.sorting_evidence_reference}</dd>
                  <dd className="text-muted">
                    Inspected: {data.sorting_inspected_quantity ?? 'Unknown'} · Approved:{' '}
                    {formatDateTime(data.sorting_approved_at)}
                  </dd>
                </div>
              )}
              <div className="col-span-full">
                <dt className="text-2xs uppercase tracking-wider text-muted">Defect description</dt>
                <dd className="whitespace-pre-line">{data.defect_description}</dd>
              </div>
              {data.concession_approval_reference && (
                <div className="col-span-full">
                  <dt className="text-2xs uppercase tracking-wider text-muted">
                    Concession approval
                  </dt>
                  <dd className="break-words">{data.concession_approval_reference}</dd>
                </div>
              )}
              {data.root_cause && (
                <div className="col-span-full">
                  <dt className="text-2xs uppercase tracking-wider text-muted">Root cause</dt>
                  <dd className="whitespace-pre-line">{data.root_cause}</dd>
                </div>
              )}
              {data.corrective_action && (
                <div className="col-span-full">
                  <dt className="text-2xs uppercase tracking-wider text-muted">
                    Corrective action
                  </dt>
                  <dd className="whitespace-pre-line">{data.corrective_action}</dd>
                </div>
              )}
            </dl>
          </Panel>

          {data.late_incoming && (
            <Panel title="Accepted material review">
              <p className="text-sm text-muted">
                Suspect remaining quantity: {formatQuantity(data.late_hold_quantity ?? 0)}. Complete
                every hold before closing. If a physical hold is missing, restore quarantine setup
                and release reservations first. Review consumed material through downstream
                traceability.
              </p>
              {!isTerminal &&
                can('inventory.mrb.manage') &&
                Number(data.late_hold_quantity ?? 0) >
                  (data.material_review_records ?? []).reduce(
                    (total, record) => total + Number(record.quantity),
                    0,
                  ) && (
                  <Button
                    className="mt-3"
                    variant="secondary"
                    onClick={() => retryQuarantine.mutate()}
                    loading={retryQuarantine.isPending}
                    disabled={retryQuarantine.isPending}
                  >
                    Retry missing quarantine holds
                  </Button>
                )}
              {data.material_review_records?.map((record) => (
                <p key={record.id} className="mt-2 text-sm">
                  {can('inventory.mrb.view') ? (
                    <Link
                      className="font-mono text-accent hover:underline"
                      to={`/inventory/mrb/${record.id}`}
                    >
                      {record.mrb_number}
                    </Link>
                  ) : (
                    record.mrb_number
                  )}{' '}
                  <Chip variant={chipVariantForStatus(record.status)}>{record.status}</Chip>
                  · {formatQuantity(record.quantity)}
                </p>
              ))}
            </Panel>
          )}
          {!isTerminal && (canManageNcr || reviewerOnly) && (
            <Panel title={mrbLocked ? 'Root cause & corrective action' : 'Set disposition'}>
              <div className="space-y-4">
                {data.is_incoming_mrb && (
                  <div className="mb-3 rounded-md border border-info/40 bg-info-bg/20 px-3 py-2 text-sm text-info-fg">
                    This supplier receipt remains on hold. Return defective pieces to the supplier.
                    Record any verified good pieces separately for acceptance, with sorting and
                    reinspection evidence. Leave the good quantity blank to return the whole lot.
                  </div>
                )}
                {data.is_process_recovery && !trialRecovery && (
                  <p className="mb-3 text-sm text-muted">
                    Production remains held. Correct the process and verify the required process
                    sample, then obtain independent approval before continuing. Account for affected
                    material separately; outgoing inspection checks the produced batch.
                  </p>
                )}
                {data.is_outgoing_recovery && !repairBatch && (
                  <p className="mb-3 text-sm text-muted">
                    {fullBatch
                      ? 'Choose what happens to defective pieces. Enter the conforming quantity to release after physical separation.'
                      : 'Choose a disposition for the remaining defects. Release verified good pieces only after full-batch sorting and reinspection.'}
                  </p>
                )}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  <Select
                    label="Disposition"
                    aria-label="Disposition"
                    required={!mrbLocked}
                    value={effectiveDisposition}
                    disabled={mrbLocked || setDispositionMut.isPending}
                    helper={
                      mrbLocked
                        ? 'Fixed by the MRB decision — the receipt was settled against it.'
                        : fullBatch && !repairBatch
                          ? 'Applies to defective pieces; conforming stock is handled separately.'
                          : undefined
                    }
                    onChange={(e) => setDisposition(e.target.value as NcrDisposition | '')}
                  >
                    <option value="">Choose how to handle defects</option>
                    {(options?.dispositions ?? [])
                      .filter(
                        (option) =>
                          (!data.is_incoming_mrb || option.value === 'return_to_supplier') &&
                          (data.severity !== 'critical' || option.value !== 'use_as_is') &&
                          (!data.is_incoming_mrb || option.value !== 'scrap') &&
                          (!data.is_outgoing_recovery ||
                            ['scrap', 'rework'].includes(option.value)) &&
                          (!data.is_process_recovery || option.value === 'rework'),
                      )
                      .map((option) => (
                        <option key={option.value} value={option.value}>
                          {fullBatch && option.value === 'rework'
                            ? 'Repair defective pieces'
                            : fullBatch && option.value === 'scrap'
                              ? 'Scrap defective pieces'
                              : option.label}
                        </option>
                      ))}
                  </Select>
                </div>
                {canKeepGood && !mrbLocked && (
                  <Input
                    label={repairBatch ? 'Good pieces to release' : 'Verified good quantity after sorting'}
                    required={repairBatch}
                    helper={
                      repairBatch ? 'From QC results. Change only if fewer good pieces can release.' : fullBatch
                        ? `Enter physically separated conforming pieces${conformingQuantity !== null ? `, up to ${conformingQuantity}` : ''}. Enter 0 if none can release.`
                        : trialRecovery
                          ? 'Enter actual trial pieces kept good, including 0. Good plus scrap must equal the affected batch.'
                          : data.is_outgoing_recovery && effectiveDisposition === 'rework'
                            ? 'Enter verified good pieces, including 0 if all need repair. A repair work order covers the remainder.'
                            : 'Leave blank to hold or dispose of the whole lot. Enter only segregated, reinspected good material; must be less than the held quantity.'
                    }
                    type="number"
                    step={countable ? '1' : '0.001'}
                    min="0"
                    max={conformingQuantity ?? recoveryQuantity}
                    containerClassName="sm:max-w-sm"
                    inputMode={countable ? 'numeric' : 'decimal'}
                    value={mrbAcceptedQuantity}
                    disabled={setDispositionMut.isPending}
                    error={
                      !goodQuantityValid && mrbAcceptedQuantity !== ''
                        ? fullBatch && conformingQuantity !== null
                          ? `Enter a whole number from 0 to ${conformingQuantity}.`
                          : data.is_outgoing_recovery && effectiveDisposition === 'rework'
                            ? 'Enter a whole number no greater than the held batch.'
                            : 'Enter a valid quantity smaller than the held quantity.'
                        : undefined
                    }
                    onChange={(e) => setMrbAcceptedQuantity(e.target.value)}
                  />
                )}
                {trialRecovery && !mrbLocked && canKeepGood && (
                  <div className="mt-3 space-y-3">
                    <p className="text-sm text-muted">
                      These counts cover only the {recoveryQuantity} recorded trial pieces. Normal
                      production stays blocked until an independent authorized reviewer approves
                      recovery.
                    </p>
                    <Input
                      label="Pieces scrapped"
                      required
                      type="number"
                      min={0}
                      max={recoveryQuantity}
                      step={1}
                      value={trialScrap}
                      disabled={setDispositionMut.isPending}
                      onChange={(event) => setTrialScrap(event.target.value)}
                    />
                    {(Number(trialScrap) > 0 || trialDefects.length > 0) && (
                      <>
                        <Button
                          type="button"
                          variant="secondary"
                          disabled={setDispositionMut.isPending}
                          onClick={() =>
                            setTrialDefects([...trialDefects, { defect_type_id: '', count: '' }])
                          }
                        >
                          Add trial defect
                        </Button>
                        {trialDefectOptions.isError && (
                          <p role="alert">
                            Defect types failed to load.{' '}
                            <Button onClick={() => trialDefectOptions.refetch()}>
                              Retry defect types
                            </Button>
                          </p>
                        )}
                        {trialDefects.map((row, index) => (
                          <div key={index} className="grid gap-2 sm:grid-cols-[1fr_6rem_auto]">
                            <Select
                              label={`Trial defect type ${index + 1}`}
                              value={row.defect_type_id}
                              disabled={setDispositionMut.isPending}
                              onChange={(event) =>
                                setTrialDefects(
                                  trialDefects.map((item, i) =>
                                    i === index
                                      ? { ...item, defect_type_id: event.target.value }
                                      : item,
                                  ),
                                )
                              }
                            >
                              <option value="">Choose defect</option>
                              {trialDefectOptions.data?.map((type) => (
                                <option key={type.id} value={type.id}>
                                  {type.code} · {type.name}
                                </option>
                              ))}
                            </Select>
                            <Input
                              label={`Trial defect count ${index + 1}`}
                              type="number"
                              min={1}
                              step={1}
                              value={row.count}
                              disabled={setDispositionMut.isPending}
                              onChange={(event) =>
                                setTrialDefects(
                                  trialDefects.map((item, i) =>
                                    i === index ? { ...item, count: event.target.value } : item,
                                  ),
                                )
                              }
                            />
                            <Button
                              type="button"
                              variant="ghost"
                              disabled={setDispositionMut.isPending}
                              onClick={() =>
                                setTrialDefects(trialDefects.filter((_, i) => i !== index))
                              }
                            >
                              Remove trial defect {index + 1}
                            </Button>
                          </div>
                        ))}
                      </>
                    )}
                    {!trialDispositionValid && (
                      <p className="text-sm text-warning-fg">
                        Good plus scrap must equal {recoveryQuantity}; defect counts must equal
                        scrap.
                      </p>
                    )}
                  </div>
                )}
                {needsSortingEvidence && (
                  <div className="mt-3 space-y-3 border-t border-default pt-3">
                    <h3 className="text-sm font-medium">
                      {fullBatch
                        ? 'Confirm physical separation'
                        : 'Sorting and verification evidence'}
                    </h3>
                    {!repairBatch && <p className="text-xs text-muted">
                      {data.is_process_recovery ? 'Required process sample' : 'Full held quantity'}:{' '}
                      <span className="font-mono">{recoveryQuantity}</span>.
                      {data.full_piece_inspection
                        ? 'Full inspection is already recorded. Confirm defects were separated; no repeat inspection is required.'
                        : data.is_process_recovery
                          ? 'Record verification of the corrected process.'
                          : 'Record completed sorting and reinspection, not the original sample result.'}
                    </p>}
                    <div className="grid gap-3 sm:grid-cols-2">
                      <Input
                        label={
                          data.full_piece_inspection
                            ? 'Quantity covered by final inspection'
                            : data.is_process_recovery
                              ? 'Process sample verified after correction'
                              : 'Quantity inspected after full-batch sorting'
                        }
                        required
                        type="number"
                        min={countable ? '1' : '0.001'}
                        step={countable ? '1' : '0.001'}
                        value={coveredQuantity}
                        readOnly={fullBatch}
                        disabled={setDispositionMut.isPending}
                        helper={
                          repairBatch ? undefined : fullBatch
                            ? 'From the completed final inspection.'
                            : data.is_process_recovery
                              ? 'Must equal the required process sample.'
                              : 'Must equal the full held quantity.'
                        }
                        error={
                          coveredQuantity !== '' && Number(coveredQuantity) !== recoveryQuantity
                            ? data.is_process_recovery
                              ? 'Verify the required process sample before continuing.'
                              : 'Inspect the full held quantity before releasing good material.'
                            : undefined
                        }
                        onChange={(event) => setSortingQuantity(event.target.value)}
                      />
                      <Select
                        label={
                          data.full_piece_inspection
                            ? 'Defects separated by'
                            : 'Sorting and reinspection performed by'
                        }
                        aria-label={
                          data.full_piece_inspection
                            ? 'Defects separated by'
                            : 'Sorting and reinspection performed by'
                        }
                        helper={
                          repairBatch ? 'Defaults to QC inspector; change if someone else separated defects.' : fullBatch
                            ? 'Select the person who physically separated the pieces.'
                            : 'Select the QC performer. A different authorized user must approve.'
                        }
                        required
                        value={sortingPerformer}
                        disabled={setDispositionMut.isPending}
                        onChange={(event) => setSortingPerformer(event.target.value)}
                      >
                        <option value="">Choose performer</option>
                        {assignees.map((assignee) => (
                          <option key={assignee.id} value={assignee.id}>
                            {assignee.name}
                          </option>
                        ))}
                      </Select>
                    </div>
                    <Input
                      label={
                        repairBatch ? 'Separation reference' : data.full_piece_inspection
                          ? 'Segregation evidence reference'
                          : 'Sorting / reinspection evidence reference'
                      }
                      required
                      maxLength={500}
                      value={sortingReference}
                      disabled={setDispositionMut.isPending}
                      helper={
                        repairBatch ? undefined : data.full_piece_inspection
                          ? 'Reference the segregation record or held-lot label.'
                          : 'Reference the external sorting and reinspection document and segregated good-lot label.'
                      }
                      placeholder={
                        fullBatch ? 'Held-lot label or log reference' : undefined
                      }
                      onChange={(event) => setSortingReference(event.target.value)}
                    />
                    {needsConcession && (
                      <Input
                        label="Concession approval / customer sign-off reference"
                        required
                        maxLength={500}
                        value={concessionReference}
                        disabled={setDispositionMut.isPending}
                        helper="Reference authorized acceptance of the known deviation, separate from reinspection."
                        onChange={(event) => setConcessionReference(event.target.value)}
                      />
                    )}
                    <div className="flex flex-wrap gap-x-5 gap-y-3">
                    <Checkbox
                      id={`ncr-recovery-${id}`}
                      className="min-h-11"
                      checked={recoveryPassed}
                      disabled={setDispositionMut.isPending}
                      label={
                        repairBatch ? 'Good pieces match inspection results.' : data.full_piece_inspection
                          ? 'Conforming pieces match the completed inspection evidence.'
                          : data.is_process_recovery
                            ? 'The corrected process passed all required checks.'
                            : needsConcession
                              ? 'Every held piece was reinspected and meets the approved concession.'
                              : 'Remaining good pieces were segregated, reinspected and passed all requirements.'
                      }
                      onChange={(event) => setRecoveryPassed(event.target.checked)}
                    />
                    {data.full_piece_inspection && (
                      <Checkbox
                        label={repairBatch ? 'Defective pieces physically separated.' : 'Defective pieces are physically separated from conforming pieces.'}
                        checked={defectsSegregated}
                        disabled={setDispositionMut.isPending}
                        onChange={(event) => setDefectsSegregated(event.target.checked)}
                      />
                    )}
                    </div>
                    {repairBatch ? (
                      <p className="text-sm font-medium" aria-live="polite">
                        {mrbAcceptedQuantity !== '' && goodQuantityValid
                          ? `${formatQuantity(Number(mrbAcceptedQuantity))} to release · ${formatQuantity(recoveryQuantity - Number(mrbAcceptedQuantity))} held for repair`
                          : `${formatQuantity(recoveryQuantity)} pieces on hold`}
                      </p>
                    ) : <p className="text-sm">
                      Verified good:{' '}
                      {effectiveDisposition === 'use_as_is'
                        ? recoveryQuantity
                        : mrbAcceptedQuantity}{' '}
                      · Remainder:{' '}
                      {effectiveDisposition === 'use_as_is'
                        ? 0
                        : formatQuantity(recoveryQuantity - Number(mrbAcceptedQuantity))}
                    </p>}
                    <p className="text-xs text-muted">
                      {fullBatch ? 'QC can confirm separation after inspection review. Repaired pieces need QC before release.'
                        : 'Saving release requires a different authorized user from the original inspector and separation performer.'}
                    </p>
                  </div>
                )}
                {canManageNcr && (
                  <div className="grid gap-3 border-t border-default pt-4 sm:grid-cols-2">
                    <Textarea
                      label="Root cause"
                      required={trialRecovery}
                      helper={
                        trialRecovery
                          ? data.root_cause || 'Describe the verified cause of the failed trial.'
                          : undefined
                      }
                      rows={3}
                      maxLength={5000}
                      showCounter={false}
                      placeholder="What caused this defect?"
                      value={rootCause}
                      onChange={(e) => setRootCause(e.target.value)}
                    />
                    {!repairNotes && <Textarea
                      label="Corrective action"
                      required={trialRecovery}
                      helper={
                        trialRecovery
                          ? data.corrective_action ||
                            'Describe the correction verified during reinspection.'
                          : undefined
                      }
                      rows={3}
                      maxLength={5000}
                      showCounter={false}
                      placeholder="How will the cause be corrected?"
                      value={correctiveAction}
                      onChange={(e) => setCorrectiveAction(e.target.value)}
                    />}
                  </div>
                )}
                {setDispositionMut.isError && (
                  <p role="alert" className="text-sm text-danger-fg">
                    {(setDispositionMut.error as AxiosError<{ message?: string }>).response?.data
                      ?.message ?? 'Could not save disposition. Your entries are still here.'}
                  </p>
                )}
                <div className="flex flex-wrap items-center justify-end gap-3 border-t border-default pt-4">
                  <p className="text-xs text-muted">
                    {!effectiveDisposition
                      ? 'Choose a disposition to continue.'
                      : repairBatch ? (!goodQuantityValid || !sortingValid ? 'Complete required fields and confirmations.' : '')
                      : !goodQuantityValid
                        ? 'Check the verified good quantity.'
                        : !sortingValid
                          ? 'Complete evidence and confirmations before saving.'
                          : needsSortingEvidence
                            ? fullBatch ? 'QC may release conforming pieces after reviewed inspection and confirmed separation.' : 'Only an authorized, independent approver can release stock.'
                            : 'Save the disposition and corrective notes.'}
                  </p>
                  <Button
                    variant="primary"
                    disabled={
                      !effectiveDisposition ||
                      !goodQuantityValid ||
                      !trialDispositionValid ||
                      (trialRecovery &&
                        !mrbLocked &&
                        (!(rootCause.trim() || data.root_cause) ||
                          !(correctiveAction.trim() || data.corrective_action))) ||
                      !sortingValid ||
                      !concessionValid ||
                      (reviewerOnly && !positiveGood) ||
                      criticalConcession
                    }
                    loading={setDispositionMut.isPending}
                    onClick={() => setDispositionMut.mutate()}
                  >
                    {mrbLocked ? 'Save' : repairBatch ? 'Approve release and repair' : 'Save disposition'}
                  </Button>
                </div>
                {data.is_outgoing_recovery && (
                  <details className="border-t border-default pt-3 text-xs text-muted">
                    <summary className="cursor-pointer focus-visible:outline-accent">
                      Warehouse handling requirements
                    </summary>
                    <p className="mt-2">
                      Scrap and repair require affected stock in one held lot bin. If it spans bins,
                      ask Warehouse to consolidate the held pieces first.
                    </p>
                  </details>
                )}
              </div>
            </Panel>
          )}

          {!fullBatch && !isTerminal && can('quality.ncr.manage') && (
            <Panel title="Add action">
              <div className="space-y-3">
                <p className="text-xs text-muted">
                  Record containment, corrective, or preventive work. Each action stays in the NCR
                  history.
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  <Select
                    label="Type"
                    value={actionType}
                    onChange={(e) => setActionType(e.target.value as NcrActionType)}
                  >
                    {(options?.actions ?? []).map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </div>
                <Textarea
                  label="Description"
                  required
                  maxLength={5000}
                  showCounter={false}
                  placeholder="Describe what was done or what needs to happen."
                  rows={3}
                  value={actionDesc}
                  onChange={(e) => setActionDesc(e.target.value)}
                />
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <Select
                    label="CAPA owner (optional)"
                    value={ownerId}
                    onChange={(e) => setOwnerId(e.target.value)}
                  >
                    <option value="">Actor (default)</option>
                    {assignees.map((assignee) => (
                      <option key={assignee.id} value={assignee.id}>
                        {assignee.name}
                      </option>
                    ))}
                  </Select>
                  <Input
                    label="Due date (optional)"
                    type="date"
                    value={dueDate}
                    onChange={(e) => setDueDate(e.target.value)}
                  />
                </div>
                <div className="flex justify-end mt-3">
                  <Button
                    variant="secondary"
                    size="sm"
                    icon={<LuPlus size={14} />}
                    disabled={!actionDesc.trim()}
                    loading={addAction.isPending}
                    onClick={() => addAction.mutate()}
                  >
                    Add action
                  </Button>
                </div>
                {addAction.isError && (
                  <p role="alert" className="text-sm text-danger-fg">
                    {(addAction.error as AxiosError<{ message?: string }>).response?.data
                      ?.message ?? 'Could not add action. Your entries are still here.'}
                  </p>
                )}
              </div>
            </Panel>
          )}
          {data.status === 'closed' &&
            capaActions.map((action) => (
              <ActionEffectivenessPanel
                key={`${action.id}-${action.effectiveness_status ?? 'unscheduled'}`}
                ncrId={id}
                action={action}
                canManage={can('quality.ncr.manage')}
              />
            ))}
        </div>

        <div className="min-w-0 space-y-4">
          {linkedGroups.length > 0 && (
            <Panel title="Linked records">
              <LinkedRecords groups={linkedGroups} />
            </Panel>
          )}
          {activityItems.length > 0 && (
            <Panel title="Activity">
              <ActivityStream items={activityItems} />
            </Panel>
          )}
          <Panel title="Navigation">
            <Link to="/quality/ncrs" className="inline-flex items-center gap-1 text-xs text-accent hover:underline">
              <LuArrowLeft size={14} aria-hidden="true" /> Back to NCRs
            </Link>
            <Link
              to="/quality/ncrs/effectiveness"
              className="mt-2 flex items-center gap-1 text-xs text-accent hover:underline"
            >
              CAPA due queue <LuArrowRight size={14} aria-hidden="true" />
            </Link>
          </Panel>
        </div>
      </div>

      <ConfirmDialog
        isOpen={confirmClose}
        title="Close NCR?"
        description={
          data.disposition === 'scrap'
            ? 'On scrap from outgoing inspection, a replacement work order will be auto-created.'
            : data.disposition === 'return_to_supplier'
              ? 'Purchasing will be notified to coordinate the supplier return.'
              : 'This will lock the NCR. Actions can no longer be added after closure.'
        }
        confirmLabel="Close NCR"
        onConfirm={() => close.mutate()}
        onClose={() => setConfirmClose(false)}
        pending={close.isPending}
      />
      <ReasonDialog
        isOpen={confirmCancel}
        title="Cancel NCR?"
        description="The reason will be appended to the corrective action notes."
        confirmLabel="Cancel NCR"
        onConfirm={(reason) => cancel.mutate(reason)}
        onClose={() => setConfirmCancel(false)}
        pending={cancel.isPending}
      />
    </div>
  );
}

function ActionEffectivenessPanel({
  ncrId,
  action,
  canManage,
}: {
  ncrId: string;
  action: NcrAction;
  canManage: boolean;
}) {
  const qc = useQueryClient();
  const [status, setStatus] =
    useState<Exclude<EffectivenessStatus, 'pending_verification'>>('effective');
  const [notes, setNotes] = useState(action.effectiveness_notes ?? '');
  const terminal =
    action.effectiveness_status === 'effective' || action.effectiveness_status === 'not_applicable';
  const canVerify =
    canManage &&
    !terminal &&
    (action.effectiveness_status === 'pending_verification' ||
      action.effectiveness_status === 'ineffective');
  const verify = useMutation({
    mutationFn: () =>
      ncrsApi.verifyAction(ncrId, action.id, { effectiveness_status: status, notes }),
    onSuccess: () => {
      toast.success('CAPA effectiveness recorded');
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', ncrId] });
    },
    onError: (error: AxiosError<{ message?: string }>) => {
      toast.error(
        error.response?.data?.message ??
          'This CAPA changed before it could be verified. Reload and try again.',
      );
    },
  });

  return (
    <Panel title={`${action.action_type_label ?? action.action_type} effectiveness`}>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Chip
          variant={
            terminal
              ? 'success'
              : action.effectiveness_status === 'ineffective'
                ? 'danger'
                : 'warning'
          }
        >
          {action.effectiveness_status_label ?? action.effectiveness_status ?? 'Not scheduled'}
        </Chip>
        {action.owner && <span className="text-muted">Owner: {action.owner.name}</span>}
        {action.next_effectiveness_check_at && (
          <span className="text-muted">
            Due: <span className="font-mono">{action.next_effectiveness_check_at}</span>
          </span>
        )}
      </div>
      {action.effectiveness_notes && (
        <p className="mt-2 text-sm whitespace-pre-line">{action.effectiveness_notes}</p>
      )}
      {canVerify && (
        <div className="mt-3 space-y-3">
          <Select
            label="Verdict"
            value={status}
            onChange={(event) => setStatus(event.target.value as typeof status)}
          >
            <option value="effective">Effective</option>
            <option value="ineffective">Ineffective — schedule follow-up</option>
            <option value="not_applicable">Not applicable</option>
          </Select>
          <Textarea
            label="Verification notes"
            required
            rows={3}
            maxLength={2000}
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />
          <div className="flex justify-end">
            <Button
              variant="secondary"
              size="sm"
              loading={verify.isPending}
              disabled={!notes.trim()}
              onClick={() => verify.mutate()}
            >
              Record verdict
            </Button>
          </div>
        </div>
      )}
    </Panel>
  );
}
