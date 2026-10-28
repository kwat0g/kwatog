/** Batch decisions replace measurement entry at every inspection stage. */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { Navigate } from 'react-router-dom';
import { LuCheck, LuBan, LuFileDown } from '@/lib/icons';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { inspectionsApi } from '@/api/quality/inspections';
import { analyticsApi } from '@/api/quality/analytics';
import { capabilityApi } from '@/api/quality/capability';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { usePermission } from '@/hooks/usePermission';
import { inspectionStatusVariant } from '@/lib/statusVariants';
import { formatDateTime } from '@/lib/formatDate';
import { formatQuantity } from '@/lib/formatNumber';
import { InspectionChecklistPanel } from './components/InspectionChecklistPanel';

export default function InspectionDetailPage() {
  const navigate = useNavigate();
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [confirmReviewPass, setConfirmReviewPass] = useState(false);
  // The decision the reason dialog will submit — set by the button clicked,
  // not inferred from proposed_result (which made "Fail inspection" submit a pass).
  const [reasonDecision, setReasonDecision] = useState<'failed' | null>(null);
  const [cocLoading, setCocLoading] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'inspections', id],
    queryFn: () => inspectionsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
    // Measurement recording and checker review happen in other sessions.
    // Poll while the verdict is not final so the page converges on its own.
    refetchInterval: (query) =>
      query.state.data && !['passed', 'failed', 'cancelled'].includes(query.state.data.status)
        ? 15_000
        : false,
  });
  const { data: inspectionOptions } = useQuery({
    queryKey: ['quality', 'inspections', 'options'],
    queryFn: inspectionsApi.options,
    staleTime: 300_000,
  });
  const { data: inspectionChain } = useQuery({
    queryKey: ['quality', 'inspections', id, 'chain'],
    queryFn: () => inspectionsApi.chain(id),
    enabled: Boolean(id),
    staleTime: 30_000,
  });
  const confirmedCheckCapture =
    data?.counted_capture?.version === 3 &&
    data.counted_capture.evidence_method === 'confirmed_checks';
  const { data: spcOptions } = useQuery({
    queryKey: ['quality', 'spc', 'options'],
    queryFn: capabilityApi.options,
    enabled: data?.capture_method === 'measurement' && !confirmedCheckCapture && can('quality.specs.view'),
    staleTime: 300_000,
  });
  const spc = useQuery({
    queryKey: ['quality', 'spc', data?.spec?.id],
    queryFn: () => analyticsApi.spcForSpec(data!.spec!.id),
    enabled: Boolean(
      data?.capture_method === 'measurement' && !confirmedCheckCapture && data?.spec?.id && can('quality.specs.view'),
    ),
    staleTime: 300_000,
  });
  const statusLabel = inspectionOptions?.statuses?.find(
    (option) => option.value === data?.status,
  )?.label;
  const stageLabel = inspectionOptions?.stages?.find(
    (option) => option.value === data?.stage,
  )?.label;
  const cancel = useMutation({
    mutationFn: (reason: string) =>
      inspectionsApi.cancel(
        id,
        reason,
        data?.capture_method === 'checklist' || data?.counted_capture ? data.result_version : undefined,
      ),
    onSuccess: () => {
      toast.success('Inspection cancelled');
      qc.invalidateQueries({ queryKey: ['quality', 'inspections'] });
      setConfirmCancel(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Could not cancel');
    },
  });

  const [confirmReplace, setConfirmReplace] = useState(false);
  const replace = useMutation({
    mutationFn: (reason: string) =>
      inspectionsApi.replaceChecklist(id, reason, data?.result_version ?? 0),
    onSuccess: (next) => {
      toast.success('New PASS/FAIL inspection created. Historical evidence preserved.');
      window.location.assign(`/quality/inspections/${next.id}`);
    },
    onError: (error: AxiosError<{ message?: string }>) =>
      toast.error(error.response?.data?.message ?? 'Could not replace this inspection.'),
  });
  const review = useMutation({
    mutationFn: ({ decision, remarks }: { decision: 'passed' | 'failed'; remarks?: string }) =>
      inspectionsApi.review(
        id,
        decision,
        remarks,
        data?.capture_method === 'checklist' || data?.counted_capture ? data.result_version : undefined,
      ),
    onSuccess: (insp) => {
      toast.success(`Inspection ${insp.status === 'passed' ? 'approved' : 'failed'} by checker.`);
      qc.invalidateQueries({ queryKey: ['quality', 'inspections'] });
      setConfirmReviewPass(false);
      setReasonDecision(null);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Could not review inspection.'),
  });

  if (isLoading && !data) {
    return <SkeletonDetail />;
  }
  if (isError || !data) {
    return (
      <div>
        <EmptyState
          icon="alert-circle"
          title="Failed to load inspection"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );
  }

  const isTerminal = ['awaiting_review', 'passed', 'failed', 'cancelled'].includes(data.status);
  const evidence = (data.measurements ?? []).filter(
    (row) => row.measured_value != null || row.is_pass != null || Boolean(row.notes),
  );
  const thresholds = spcOptions?.capability_thresholds;
  const waitingForTrial = Boolean(data.startup_trial_required && (!data.startup_trial ||
    data.startup_trial.recorded_quantity !== data.startup_trial.required_quantity || !data.startup_trial.output_id));
  const hasTrial = Boolean(data.startup_trial?.output_id);
  const hasRequiredSizeReadings = Boolean(data.measurements?.some((row) =>
    row.evaluation_mode === 'numeric' ||
    (row.parameter_type === 'dimensional' &&
      (row.tolerance_min != null || row.tolerance_max != null)),
  ));
  const nextAction =
    waitingForTrial ? `Production: record ${data.startup_trial?.required_quantity ?? data.sample_size} trial pieces before QC evidence can be entered.` : data.status === 'awaiting_review'
      ? `Independent QC reviewer: review the proposed ${(data.proposed_result ?? 'result').toUpperCase()}.`
      : data.status === 'failed'
        ? data.ncr
          ? `QC disposition owner: follow ${data.ncr.ncr_number}. Stock remains subject to its disposition and release checks.`
          : data.count_only_incoming && Number(data.sample_defect_count) > 0
            ? `QC confirmed ${data.sample_defect_count} defective pieces. Warehouse accepts the good pieces; Purchasing reviews their supplier return in Return Management.`
            : 'QC disposition owner: inspect the failed source and reconcile its recovery handoff before stock release.'
        : data.status === 'passed'
          ? data.stage === 'incoming'
            ? `Warehouse: ${data.incoming_acceptance?.status === 'accepted' ? 'linked receipt is accepted.' : 'check the linked receipt for material acceptance.'}`
            : 'Inspection passed. Check linked source records for the next production, return, or warehouse action.'
          : data.status === 'cancelled'
            ? 'Cancelled evidence cannot authorize stock release.'
            : data.counted_capture ? data.sampling_basis === 'lot'
              ? hasRequiredSizeReadings ? 'QC: confirm material defects and enter required size readings, then submit.' : 'QC: confirm material defects, then submit inspection.'
              : hasRequiredSizeReadings ? 'QC: record defective pieces and required size readings, then submit.' : 'QC: record defective pieces, then submit inspection.'
              : data.count_only_incoming ? 'QC inspector: count every piece, record and segregate defects, then submit.' : 'QC inspector: complete the checklist, then submit inspection.';

  if (
    data.counted_capture?.workflow === 'finish_first_v1' &&
    [2, 3].includes(data.counted_capture.version) &&
    data.final_work_order_id &&
    data.status !== 'cancelled'
  ) {
    return <Navigate replace to={`/quality/work-orders/${data.final_work_order_id}/final-inspection`} />;
  }

  return (
    <div>
      <PageHeader
        backTo="/quality/inspections"
        backLabel="Back to inspections"
        title={
          <span>
            {data.inspection_number}
            <Chip variant={inspectionStatusVariant[data.status]} className="ml-3">
              {data.status_label ?? statusLabel ?? data.status}
            </Chip>
          </span>
        }
        subtitle={
          data.product
            ? `${data.product.part_number} — ${data.product.name}`
            : data.item
              ? `${data.item.code} — ${data.item.name}`
              : (stageLabel ?? data.stage)
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {data.ncr?.href && (
              <Button variant="secondary" size="sm" onClick={() => navigate(data.ncr!.href!)}>
                Open NCR
              </Button>
            )}
            {data.work_order_output &&
              ['passed', 'failed'].includes(data.status) &&
              can('quality.ncr.manage') && (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => navigate(`/quality/ncrs/new?inspection_id=${data.id}`)}
                >
                  {data.ncr ? 'Report another defect' : 'Report defect'}
                </Button>
              )}
            {!isTerminal && !hasTrial && can('quality.inspections.manage') && (
              <>
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuBan size={14} />}
                  onClick={() => setConfirmCancel(true)}
                >
                  Cancel
                </Button>
              </>
            )}
            {can('quality.inspections.manage') && data.can_replace_checklist && !hasTrial && (
              <Button variant="secondary" onClick={() => setConfirmReplace(true)}>
                Replace with PASS/FAIL checklist
              </Button>
            )}
            {/* can_review also excludes the maker — the service refuses self-review. */}
            {data.can_review && !waitingForTrial && (
              <>
                {data.proposed_result === 'passed' ? (
                  <Button
                    variant="primary"
                    size="sm"
                    icon={<LuCheck size={14} />}
                    onClick={() => setConfirmReviewPass(true)}
                  >
                    Approve pass
                  </Button>
                ) : (
                  <Button
                    variant="danger"
                    size="sm"
                    icon={<LuBan size={14} />}
                    onClick={() => setReasonDecision('failed')}
                  >
                    Confirm failure
                  </Button>
                )}
                {data.proposed_result === 'passed' ? (
                  <Button variant="danger" size="sm" onClick={() => setReasonDecision('failed')}>
                    Fail inspection
                  </Button>
                ) : null}
              </>
            )}
            {/* CoC available for passed outgoing inspections */}
            {data.stage === 'outgoing' &&
              data.status === 'passed' &&
              (data.coc_unavailable_reason ? (
                <p role="status" className="max-w-md text-xs text-warning-fg">
                  Certificate unavailable: {data.coc_unavailable_reason}
                </p>
              ) : (
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuFileDown size={14} />}
                  loading={cocLoading}
                  onClick={async () => {
                    setCocLoading(true);
                    try {
                      await inspectionsApi.generateCoC(id);
                    } catch (error) {
                      const certificateError = error as AxiosError<{ message?: string }>;
                      toast.error(
                        certificateError.response?.data?.message ??
                          'Could not generate CoC — try again.',
                      );
                    } finally {
                      setCocLoading(false);
                    }
                  }}
                >
                  Certificate of Conformance
                </Button>
              ))}
          </div>
        }
      />

      <div className="bg-surface px-4 py-5 sm:px-5 space-y-5">
        {inspectionChain && inspectionChain.length > 0 && (
          <section
            aria-label="Record flow"
            className="rounded-md border border-default bg-canvas px-4 py-3"
          >
            <h2 className="mb-3 text-sm font-medium text-primary">Record flow</h2>
            <ChainHeader steps={inspectionChain} />
          </section>
        )}
        {!waitingForTrial && <p role="status" className="rounded-md border border-default bg-canvas px-4 py-3 text-sm">
          <span className="font-medium">Next action: </span>
          {nextAction}
        </p>}
        <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
          <section
            aria-label="Inspection details"
            className="min-w-0 rounded-md border border-default bg-canvas lg:col-span-2"
          >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-default px-4 py-3">
              <h2 className="text-sm font-medium text-primary">Inspection details</h2>
            </div>
            <div className="px-4 py-4">
              <dl className="grid grid-cols-2 gap-x-5 gap-y-3 sm:grid-cols-3">
                <div className="min-w-0">
                  <dt className="text-xs text-muted">Stage</dt>
                  <dd className="mt-1 text-sm text-primary break-words font-medium">
                    {data.stage_label ?? stageLabel ?? data.stage}
                  </dd>
                </div>
                <div className="min-w-0">
                  <dt className="text-xs text-muted">
                    {data.capture_method === 'checklist' ? 'Source quantity' : 'Batch quantity'}
                  </dt>
                  <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                    {data.capture_method === 'checklist'
                      ? `${data.source_quantity} ${data.source_uom_code}`
                      : formatQuantity(data.batch_quantity)}
                  </dd>
                </div>
                <div className="min-w-0">
                  <dt className="text-xs text-muted">
                    {data.counted_capture ? 'Checked quantity' : data.capture_method === 'checklist' &&
                    data.sampling_basis === 'piece' &&
                    data.sample_size === data.batch_quantity
                      ? 'Full piece coverage'
                      : data.sampling_basis === 'lot'
                        ? 'Lot coverage'
                        : 'Inspect sample'}
                  </dt>
                  <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                    {formatQuantity(data.counted_capture?.required_quantity ?? data.sample_size)}
                    {data.counted_capture ? data.sampling_basis === 'lot' ? ' lot' : ' pieces' : data.capture_method === 'checklist'
                      ? data.sampling_basis === 'lot'
                        ? ' lot'
                        : ' pieces'
                      : ''}
                  </dd>
                </div>
                {data.counted_capture || data.capture_method === 'checklist' ? (
                  <div className="min-w-0 sm:col-span-2">
                    <dt className="text-xs text-muted">Acceptance rule</dt>
                    <dd className="mt-1 text-sm">
                      {data.counted_capture ? `${data.count_only_incoming
                        ? 'Pass requires zero defective pieces.'
                        : data.sampling_basis === 'lot'
                        ? 'Pass requires no material defects or other failed requirements.'
                        : 'Pass requires zero defective pieces and no other failed requirements.'}${hasRequiredSizeReadings ? ' Required size readings must meet limits.' : ''}` : data.count_only_incoming
                        ? 'Count every piece. Segregate defective pieces; the good quantity is reviewed for acceptance.'
                        : 'All checks must pass. Follow the approved sampling plan.'}
                    </dd>
                  </div>
                ) : (
                  <>
                    <div className="min-w-0">
                      <dt className="text-xs text-muted">AQL code</dt>
                      <dd className="mt-1 text-sm text-primary break-words font-medium">
                        {data.aql_code ?? '—'}
                      </dd>
                    </div>
                    <div className="min-w-0">
                      <dt className="text-xs text-muted">Acceptance limit</dt>
                      <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                        {formatQuantity(data.accept_count)}
                      </dd>
                    </div>
                    <div className="min-w-0">
                      <dt className="text-xs text-muted">Rejection limit</dt>
                      <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                        {formatQuantity(data.reject_count)}
                      </dd>
                    </div>
                  </>
                )}
                {!!data.counted_capture?.dimensional_quantity && (
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">Size sample</dt>
                    <dd className="mt-1 text-sm">{data.counted_capture.dimensional_quantity} pieces</dd>
                  </div>
                )}
                <div className="min-w-0">
                  <dt className="text-xs text-muted">{data.sampling_basis === 'lot' ? 'Material defects' : data.count_only_incoming ? 'Defective pieces' : 'Sample defects'}</dt>
                  <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                    {data.sample_defect_count != null
                      ? data.sampling_basis === 'lot'
                        ? Number(data.sample_defect_count) > 0
                          ? 'Affected lot'
                          : 'No affected lot'
                        : formatQuantity(data.sample_defect_count)
                      : 'Not recorded'}
                  </dd>
                </div>
                <div className="min-w-0">
                  <dt className="text-xs text-muted">
                    {data.stage === 'outgoing'
                      ? 'Approved for release'
                      : data.stage === 'incoming'
                        ? 'Warehouse acceptance'
                        : 'Source status'}
                  </dt>
                  <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                    {data.stage === 'outgoing'
                      ? `${formatQuantity(data.accepted_quantity)} pieces`
                      : data.stage === 'incoming'
                        ? data.incoming_acceptance
                          ? `${data.incoming_acceptance.quantity_accepted} ${data.incoming_acceptance.unit} · ${data.incoming_acceptance.status_label}`
                          : (data.entity_context?.status_label ?? 'Check linked receipt')
                        : (data.entity_context?.status_label ??
                          data.entity_context?.status ??
                          'See linked source')}
                  </dd>
                </div>
                {evidence.some((row) => row.is_pass != null) && (
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">
                      {data.capture_method === 'checklist'
                        ? 'Failed size checks'
                        : 'Measurement defects'}
                    </dt>
                    <dd className="mt-1 text-sm text-primary font-mono tabular-nums">
                      {formatQuantity(data.defect_count)}
                    </dd>
                  </div>
                )}
              </dl>
            </div>
          </section>
          <div className="min-w-0 lg:col-span-2">
            {data.startup_trial && data.startup_trial.recorded_quantity > 0 && <p className="mb-3 text-sm text-muted">
              Recorded startup sample: {data.startup_trial.recorded_quantity} pieces · {formatDateTime(data.startup_trial.captured_at)}
            </p>}
            <InspectionChecklistPanel
              key={data.id}
              inspection={data}
              canManage={data.can_manage ?? can('quality.inspections.manage')}
            />
          </div>
          <aside
            aria-label="Inspection context"
            className="min-w-0 space-y-5 lg:col-start-3 lg:row-start-1 lg:row-span-4"
          >
            <section
              aria-label="Linked records"
              className="min-w-0 rounded-md border border-default bg-canvas"
            >
              <div className="border-b border-default px-4 py-3">
                <h2 className="text-sm font-medium text-primary">Linked records</h2>
              </div>
              <div className="px-4 py-4">
                {data.product ||
                data.item ||
                data.entity_context ||
                data.work_order_output ||
                data.spec ||
                data.quality_plan ? (
                  <LinkedRecords
                    className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1 [&_a]:break-words [&_span]:break-words"
                    groups={[
                      ...(data.product
                        ? [
                            {
                              label: 'Product',
                              items: [
                                {
                                  id: `${data.product.part_number} — ${data.product.name}`,
                                  href: can('crm.products.view')
                                    ? `/crm/products/${data.product.id}`
                                    : undefined,
                                },
                              ],
                            },
                          ]
                        : []),
                      ...(data.item
                        ? [
                            {
                              label: 'Inventory item',
                              items: [
                                {
                                  id: `${data.item.code} — ${data.item.name}`,
                                  href: can('inventory.view')
                                    ? `/inventory/items/${data.item.id}`
                                    : undefined,
                                },
                              ],
                            },
                          ]
                        : []),
                      ...(data.entity_context
                        ? [
                            {
                              label: 'Source record',
                              items: [
                                {
                                  id: data.entity_context.reference ?? data.entity_context.type,
                                  href: data.entity_context.href ?? undefined,
                                  meta:
                                    data.entity_context.status_label ?? data.entity_context.status,
                                },
                              ],
                            },
                          ]
                        : []),
                      ...(data.work_order_output
                        ? [
                            {
                              label: 'Output batch',
                              items: [
                                {
                                  id:
                                    data.work_order_output.batch_code ?? data.work_order_output.id,
                                  href:
                                    can('production.work_orders.view') &&
                                    data.work_order_output.work_order
                                      ? `/production/work-orders/${data.work_order_output.work_order.id}`
                                      : undefined,
                                  meta: `${data.work_order_output.work_order?.wo_number ?? 'Work order'} · good ${data.work_order_output.good_count}`,
                                },
                              ],
                            },
                          ]
                        : []),
                      ...(data.ncr
                        ? [
                            {
                              label: 'Nonconformance / recovery',
                              items: [
                                {
                                  id: data.ncr.ncr_number,
                                  href: data.ncr.href ?? undefined,
                                  meta: `${data.ncr.status_label}${data.ncr.disposition_label ? ` · ${data.ncr.disposition_label}` : ''}`,
                                },
                              ],
                            },
                          ]
                        : []),
                      ...(data.replaces_inspection_id
                        ? [
                            {
                              label: 'Preserved history',
                              items: [
                                {
                                  id: 'Previous inspection',
                                  href: `/quality/inspections/${data.replaces_inspection_id}`,
                                  meta: 'Original evidence retained',
                                },
                              ],
                            },
                          ]
                        : []),
                      ...(data.spec
                        ? [
                            {
                              label: 'Inspection spec',
                              items: [
                                {
                                  id:
                                    data.spec.version === null
                                      ? 'Legacy revision unknown'
                                      : `v${data.spec.version}`,
                                  href: !can('quality.specs.view')
                                    ? undefined
                                    : data.spec?.revision_id
                                      ? `/quality/inspection-specs/spec/${data.spec.id}?revision=${data.spec.revision_id}`
                                      : data.product
                                        ? `/quality/inspection-specs/${data.product.id}`
                                        : undefined,
                                  meta:
                                    data.spec.revision_status === 'legacy_unknown'
                                      ? 'historical revision not pinned'
                                      : data.spec.is_active
                                        ? 'active'
                                        : 'archived',
                                },
                              ],
                            },
                          ]
                        : []),
                      ...(data.quality_plan
                        ? [
                            {
                              label: 'Quality plan',
                              items: [
                                {
                                  id: `v${data.quality_plan.version}`,
                                  href:
                                    can('inventory.view') && data.item
                                      ? `/inventory/items/${data.item.id}/quality-plans`
                                      : undefined,
                                  meta: data.quality_plan.sampling_method,
                                },
                              ],
                            },
                          ]
                        : []),
                    ]}
                  />
                ) : (
                  <p className="text-sm text-muted">No linked records.</p>
                )}
              </div>
            </section>
            {data.capture_method !== 'checklist' &&
              data.spec &&
              thresholds &&
              spc.data &&
              Object.keys(spc.data).length > 0 && (
                <section
                  aria-label="SPC capability indices"
                  className="min-w-0 rounded-md border border-default bg-canvas"
                >
                  <div className="border-b border-default px-4 py-3">
                    <h2 className="text-sm font-medium text-primary">SPC capability indices</h2>
                    <p className="mt-1 text-xs text-muted">
                      Current specification revision · saved measurement history · Cpk ≥{' '}
                      {thresholds.ongoing.toFixed(2)} ongoing · ≥ {thresholds.launch.toFixed(2)} new
                      product launch
                    </p>
                  </div>
                  <div className="overflow-x-auto">
                    <table className={tableCls}>
                      <thead>
                        <tr className={theadTrCls}>
                          <Th>Dimension</Th>
                          <Th align="right">Cp / Cpk</Th>
                          <Th>Rating</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {Object.values(spc.data).map((item) => (
                          <tr key={item.parameter_name} className={trCls}>
                            <Td>
                              {item.parameter_name} {item.unit ? `(${item.unit})` : ''}
                              <p className="mt-1 text-2xs text-muted">
                                Mean{' '}
                                <span className="font-mono tabular-nums">
                                  {item.mean.toFixed(3)}
                                </span>
                                {' · '}
                                {item.sample_count} samples
                              </p>
                            </Td>
                            <Td align="right" mono>
                              {item.cp.toFixed(2)} / {item.cpk.toFixed(2)}
                            </Td>
                            <Td>
                              <Chip
                                variant={
                                  item.cpk >= thresholds.launch
                                    ? 'success'
                                    : item.cpk >= thresholds.ongoing
                                      ? 'info'
                                      : item.cpk >= thresholds.action
                                        ? 'warning'
                                        : 'danger'
                                }
                              >
                                {item.cpk >= thresholds.launch
                                  ? 'Excellent'
                                  : item.cpk >= thresholds.ongoing
                                    ? 'Capable'
                                    : item.cpk >= thresholds.action
                                      ? 'Marginal'
                                      : 'Not capable'}
                              </Chip>
                            </Td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </section>
              )}
            <section
              aria-label="Inspection history"
              className="rounded-md border border-default bg-canvas"
            >
              <h2 className="border-b border-default px-4 py-3 text-sm font-medium">
                Inspection history
              </h2>
              <div className="border-t border-default px-4 py-4">
                <dl className="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3">
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">Inspection created by</dt>
                    <dd className="mt-1 text-sm text-primary break-words font-medium">
                      {data.inspector?.name ?? '—'}
                    </dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">Results recorded by</dt>
                    <dd className="mt-1 text-sm text-primary break-words font-medium">
                      {data.result_authors?.map((author) => author.name).join(', ') ||
                        'Not recorded'}
                    </dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">Created</dt>
                    <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                      {formatDateTime(data.created_at)}
                    </dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">Started</dt>
                    <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                      {formatDateTime(data.started_at)}
                    </dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">Completed</dt>
                    <dd className="mt-1 text-sm text-primary break-words font-mono tabular-nums">
                      {formatDateTime(data.completed_at)}
                    </dd>
                  </div>
                  {data.reviewer && (
                    <div>
                      <dt className="text-xs text-muted">Reviewed by</dt>
                      <dd className="mt-1 text-sm text-primary">{data.reviewer.name}</dd>
                    </div>
                  )}
                  {data.reviewed_at && (
                    <div>
                      <dt className="text-xs text-muted">Reviewed</dt>
                      <dd className="mt-1 text-sm font-mono tabular-nums">
                        {formatDateTime(data.reviewed_at)}
                      </dd>
                    </div>
                  )}
                </dl>
              </div>
              {(data.notes || data.review_remarks || data.status === 'awaiting_review') && (
                <div className="border-t border-default px-4 py-3 space-y-3 text-sm">
                  {data.status === 'awaiting_review' && (
                    <p className="text-warning-fg">
                      Awaiting checker review · proposed{' '}
                      {(data.proposed_result ?? data.recorded_result)?.toUpperCase() ?? '—'}
                    </p>
                  )}
                  {data.notes && (
                    <div>
                      <p className="mb-1 text-xs text-muted">Notes</p>
                      <p className="whitespace-pre-line break-words">{data.notes}</p>
                    </div>
                  )}
                  {data.review_remarks && (
                    <div>
                      <p className="mb-1 text-xs text-muted">Checker remarks</p>
                      <p className="whitespace-pre-line break-words">{data.review_remarks}</p>
                    </div>
                  )}
                </div>
              )}
            </section>
          </aside>
          {!data.counted_capture && data.capture_method !== 'checklist' && evidence.length > 0 && (
            <section
              aria-label="Saved measurement evidence"
              className="min-w-0 rounded-md border border-default bg-canvas lg:col-span-2"
            >
              <div className="border-b border-default px-4 py-3">
                <h2 className="text-sm font-medium text-primary">Saved measurement evidence</h2>
                <p className="mt-1 text-xs text-muted">
                  Previously recorded readings. Batch decisions preserve this measurement evidence.
                </p>
              </div>
              <div className="overflow-x-auto">
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Sample</Th>
                      <Th>Check</Th>
                      <Th align="right">Reading</Th>
                      <Th>Saved result</Th>
                      <Th>Notes</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {evidence.map((row) => (
                      <tr key={row.id} className={trCls}>
                        <Td mono>{row.sample_index === 0 ? 'Batch' : row.sample_index}</Td>
                        <Td>{row.parameter_name}</Td>
                        <Td align="right" mono>
                          {row.measured_value ?? '—'} {row.unit_of_measure}
                        </Td>
                        <Td>
                          {row.is_pass == null ? (
                            '—'
                          ) : (
                            <Chip variant={row.is_pass ? 'success' : 'danger'}>
                              {row.is_pass ? 'PASS' : 'FAIL'}
                            </Chip>
                          )}
                        </Td>
                        <Td className="whitespace-pre-line break-words">{row.notes ?? '—'}</Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}
        </div>
      </div>
      <ReasonDialog
        isOpen={confirmReplace}
        title="Replace legacy inspection?"
        description="Creates an unanswered inspection using the approved PASS/FAIL revision. Historical evidence stays preserved; known failures require recovery instead."
        confirmLabel="Create checklist inspection"
        onConfirm={(reason) => replace.mutate(reason)}
        onClose={() => setConfirmReplace(false)}
        pending={replace.isPending}
      />
      <ReasonDialog
        isOpen={confirmCancel}
        title="Cancel inspection?"
        description="Provide a brief reason. The cancellation will be appended to this inspection's notes."
        confirmLabel="Cancel inspection"
        onConfirm={(reason) => cancel.mutate(reason)}
        onClose={() => setConfirmCancel(false)}
        pending={cancel.isPending}
      />
      <ConfirmDialog
        isOpen={confirmReviewPass}
        title="Approve inspection pass?"
        description="This checker action releases the high-risk inspection result to the downstream quality chain."
        confirmLabel="Approve pass"
        onConfirm={() => review.mutate({ decision: 'passed' })}
        onClose={() => setConfirmReviewPass(false)}
        pending={review.isPending}
      />
      <ReasonDialog
        isOpen={reasonDecision !== null}
        title={
          data.proposed_result === 'failed' ? 'Confirm inspection failure?' : 'Fail inspection?'
        }
        description="Record the checker reason. The failed verdict remains in the inspection history."
        confirmLabel={data.proposed_result === 'failed' ? 'Confirm failure' : 'Fail inspection'}
        variant="danger"
        onConfirm={(remarks) => {
          if (reasonDecision) review.mutate({ decision: reasonDecision, remarks });
        }}
        onClose={() => setReasonDecision(null)}
        pending={review.isPending}
      />
    </div>
  );
}
