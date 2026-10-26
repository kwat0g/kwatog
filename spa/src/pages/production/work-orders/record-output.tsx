/**
 * Sprint 6 — Task 55. Output recording form.
 * Subscribes to production.wo.{id} for live cumulative updates while the
 * supervisor is filling the form.
 */
import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { LuTriangleAlert, LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { workOrdersApi } from '@/api/production/workOrders';
import { client } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { TrialProductionPanel } from '@/components/production/TrialProductionPanel';
import { MaterialHandoverNotice } from '@/components/production/MaterialHandoverNotice';
import { usePermission } from '@/hooks/usePermission';
import { useEcho } from '@/hooks/useEcho';
import { formatInt } from '@/lib/formatNumber';
import type { DefectType, RecordOutputData } from '@/types/production';

const schema = z.object({
  produced_count: z.string().optional(),
  good_count: z.string().optional(),
  recording_reason: z.string().trim().min(1, 'Enter why you are recording on behalf of the crew'),
  remarks: z.string().max(500).optional().or(z.literal('')),
  defects: z.array(
    z.object({
      defect_type_id: z.string().min(1, 'Defect type required'),
      count: z
        .string()
        .regex(/^\d+$/, 'Use a positive integer')
        .refine((v) => Number(v) > 0, 'Must be > 0'),
    }),
  ),
}).superRefine((values, ctx) => {
  if (values.produced_count === undefined && values.good_count === undefined) {
    ctx.addIssue({ code: 'custom', path: ['good_count'], message: 'Enter a quantity.' });
  }
  if (values.produced_count !== undefined && (!/^\d+$/.test(values.produced_count) || Number(values.produced_count) < 1)) {
    ctx.addIssue({ code: 'custom', path: ['produced_count'], message: 'Enter a positive whole quantity.' });
  }
  if (values.produced_count === undefined && values.good_count !== undefined && !/^\d+$/.test(values.good_count)) {
    ctx.addIssue({ code: 'custom', path: ['good_count'], message: 'Good count must be a non-negative integer.' });
  }
});
type FormValues = z.infer<typeof schema>;

export default function RecordOutputPage() {
  const { id: officeId, woId } = useParams<{ id: string; woId: string }>();
  const id = officeId ?? woId;
  const { can } = usePermission();
  const canAcknowledgeHandover =
    can('production.wo.record') && can('production.work_orders.lifecycle');
  const [handoverAcknowledged, setHandoverAcknowledged] = useState(false);
  const [handoverReason, setHandoverReason] = useState('');
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [liveCumulative, setLiveCumulative] = useState<{
    produced: number;
    good: number;
    reject: number;
    scrap: string;
  } | null>(null);
  const [pending, setPending] = useState<{ data: RecordOutputData; key: string } | null>(null);
  const [uncertain, setUncertain] = useState(false);

  const wo = useQuery({
    queryKey: ['production', 'work-orders', 'detail', id],
    queryFn: () => workOrdersApi.show(id!),
    enabled: !!id,
  });
  const finishFirst = wo.data?.qc_workflow === 'finish_first_v1';

  const defects = useQuery({
    queryKey: ['production', 'defect-types'],
    queryFn: () =>
      client.get<{ data: DefectType[] }>('/production/defect-types').then((r) => r.data.data),
    enabled: !!wo.data && !finishFirst,
  });
  const crew = useQuery({
    queryKey: ['production', 'crew', id],
    queryFn: () => workOrdersApi.crew(id!),
    enabled: !!id,
  });

  // Subscribe to live updates so the cumulative panel reflects every recording.
  useEcho<{
    total_quantity_produced: number;
    total_quantity_good: number;
    total_quantity_rejected: number;
    scrap_rate: string;
  }>(`production.wo.${id}`, '.output.recorded', (p) => {
    setLiveCumulative({
      produced: p.total_quantity_produced,
      good: finishFirst ? wo.data?.qc_accepted_quantity ?? 0 : p.total_quantity_good,
      reject: finishFirst ? wo.data?.awaiting_qc_quantity ?? p.total_quantity_produced : p.total_quantity_rejected,
      scrap: p.scrap_rate,
    });
    qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
  });

  const {
    register,
    control,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { produced_count: undefined, good_count: undefined, recording_reason: '', remarks: '', defects: [] },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'defects' });

  // Live-compute reject count from defect rows.
  const watchedDefects = useWatch({ control, name: 'defects' });
  const rejectCount = useMemo(
    () => (watchedDefects ?? []).reduce((sum, d) => sum + Number(d?.count || 0), 0),
    [watchedDefects],
  );

  const submit = useMutation({
    mutationFn: ({ data, key }: { data: RecordOutputData; key: string }) =>
      workOrdersApi.recordOutput(id!, data, key),
    onSuccess: async (output) => {
      if (output.production_receipt_handoff?.status === 'manual_required') {
        toast(
          `Output ${output.batch_code ?? ''} recorded. Finished-goods receipt needs Inventory attention.`,
          { icon: <LuTriangleAlert size={16} aria-hidden="true" /> },
        );
      } else {
        toast.success(`Output ${output.batch_code ?? ''} recorded.`);
      }
      reset({ produced_count: undefined, good_count: undefined, recording_reason: '', remarks: '', defects: [] });
      setPending(null);
      setUncertain(false);
      setHandoverAcknowledged(false);
      setHandoverReason('');
      await qc.invalidateQueries({
        predicate: (query) =>
          query.queryKey[0] === 'production' &&
          query.queryKey[1] === 'work-orders' &&
          query.queryKey[2] !== 'form-options',
      });
      navigate(`/production/work-orders/${id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
      if (!e.response || e.response.status >= 500) setUncertain(true);
      else if (!uncertain) setPending(null);
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as never, { type: 'server', message: msgs[0] });
        });
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to record output.');
      }
    },
  });

  const check = useMutation({
    mutationFn: () => workOrdersApi.myOutputReceipt(id!, pending!.key),
    onSuccess: () => {
      setPending(null);
      setUncertain(false);
      qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      toast.success('Output receipt found. Recording saved.');
      navigate(`/production/work-orders/${id}`);
    },
    onError: (error) =>
      toast.error(
        error instanceof AxiosError && error.response?.status === 404
          ? 'No receipt found yet. Retry the same recording to resolve it.'
          : 'Receipt check failed. Try again.',
      ),
  });

  const handoverPending =
    wo.data?.warehouse_handover?.pending ?? wo.data?.warehouse_handover_pending ?? 0;
  const handoverBlocked =
    handoverPending > 0 &&
    !(
      canAcknowledgeHandover &&
      handoverAcknowledged &&
      handoverReason.trim() &&
      handoverReason.trim().length <= 500
    );
  const onSubmit = (values: FormValues) => {
    if (
      !crew.data?.current ||
      (!finishFirst && !defects.data) ||
      !wo.data ||
      wo.data.status !== 'in_progress' ||
      wo.data.process_quality_blocker ||
      (!finishFirst && wo.data.startup_trial && wo.data.startup_trial.state !== 'released') ||
      handoverBlocked
    )
      return;
    const common = {
      ...(handoverPending > 0 && canAcknowledgeHandover ? { material_handover_acknowledged: handoverAcknowledged, material_handover_reason: handoverReason.trim() } : {}),
      crew_id: crew.data.current.id, recording_reason: values.recording_reason, remarks: values.remarks || undefined,
    };
    const outputData: RecordOutputData = finishFirst
      ? { ...common, produced_count: Number(values.produced_count) }
      : { ...common, good_count: Number(values.good_count), reject_count: values.defects.reduce((sum, d) => sum + Number(d.count), 0),
          defects: values.defects.map(d => ({ defect_type_id: d.defect_type_id, count: Number(d.count) })) };
    const submission = { data: outputData, key: `${id}-${crypto.randomUUID()}` };
    if (pending) return;
    setPending(submission);
    submit.mutate(submission);
  };

  const cumulative =
    liveCumulative ??
    (wo.data
      ? {
          produced: wo.data.produced_quantity ?? wo.data.quantity_produced,
    good: finishFirst ? wo.data.qc_accepted_quantity ?? 0 : wo.data.quantity_good,
          reject: finishFirst ? wo.data.awaiting_qc_quantity ?? wo.data.quantity_produced : wo.data.quantity_rejected,
          scrap: wo.data.scrap_rate,
        }
      : { produced: 0, good: 0, reject: 0, scrap: '0' });
  const outputBlocked =
    handoverBlocked ||
    !crew.data?.current ||
    crew.isError ||
    (!finishFirst && (!defects.data || defects.isError)) ||
    !wo.data ||
    wo.isError ||
    wo.data.status !== 'in_progress' ||
    !!wo.data.process_quality_blocker ||
    (!finishFirst && !!wo.data.startup_trial && wo.data.startup_trial.state !== 'released');

  return (
    <PageShell variant="grid">
      <PageHeader
        inset={false}
        title={`${finishFirst ? 'Record production' : 'Record output'}${wo.data ? ` — ${wo.data.wo_number}` : ''}`}
        backTo={`/production/work-orders/${id}`}
        backLabel="Work order"
      />
      <div className="min-w-0 grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 lg:col-span-2">
          {wo.isLoading && (
            <p role="status" className="mb-3 text-sm text-muted">
              Loading work order…
            </p>
          )}
          {wo.isError && (
            <p role="alert" className="mb-3 text-sm text-danger-fg">
              Work order details failed to load.{' '}
              <Button type="button" variant="secondary" size="sm" onClick={() => void wo.refetch()}>
                Retry work order
              </Button>
            </p>
          )}
          {wo.data?.startup_trial && !finishFirst && <div className="mb-4"><TrialProductionPanel key={`${id}-${wo.data.startup_trial.inspection_id}`} workOrderId={id!}
            trial={wo.data.startup_trial} crew={crew.data?.current} manager canRecord={can('production.wo.record')}
            handoverPending={handoverPending} canAcknowledge={canAcknowledgeHandover}
            blocked={wo.data.status !== 'in_progress' || wo.isError || crew.isError || !!pending} /></div>}
          {wo.data?.process_quality_blocker && (!wo.data.startup_trial || finishFirst) && (
            <div role="alert" className="mb-3 rounded-md border border-warning p-3 text-sm">
              <p>{wo.data.process_quality_blocker}</p>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                disabled={wo.isFetching}
                loading={wo.isFetching}
                onClick={() => void wo.refetch()}
              >
                Recheck QC
              </Button>
            </div>
          )}
          {wo.data && wo.data.status !== 'in_progress' && (
            <p role="alert" className="mb-3 text-sm text-warning-fg">
              Output can only be recorded while the work order is in progress.
            </p>
          )}
          {crew.isLoading && <p role="status">Loading crew…</p>}
          {crew.isError && (
            <p role="alert">
              Crew failed to load. <Button onClick={() => crew.refetch()}>Retry crew</Button>
            </p>
          )}
          {crew.data && !crew.data.current && (
            <p role="alert">Assign a crew before recording output.</p>
          )}
          {defects.isError && (
            <p role="alert">
              Defect types failed to load.{' '}
              <Button onClick={() => defects.refetch()}>Retry defect types</Button>
            </p>
          )}
          <form onSubmit={handleSubmit(onSubmit, onFormInvalid<FormValues>())}>
            <Panel title="New recording">
              <div className="mb-3">
                <MaterialHandoverNotice
                  pending={handoverPending}
                  canAcknowledge={canAcknowledgeHandover}
                  acknowledged={handoverAcknowledged}
                  reason={handoverReason}
                  onAcknowledged={setHandoverAcknowledged}
                  onReason={setHandoverReason}
                  disabled={!!pending}
                />
              </div>
              <fieldset disabled={!!pending} className="min-w-0">
                <div className="grid grid-cols-2 gap-3">
                  <Input
                    key={finishFirst ? 'produced' : 'good'}
                    label={finishFirst ? 'Quantity made' : 'Good count'}
                    aria-label={finishFirst ? 'Quantity made' : undefined}
                    required
                    type="number"
                    min={finishFirst ? 1 : 0}
                    step={1}
                    {...register(finishFirst ? 'produced_count' : 'good_count')}
                    error={finishFirst ? errors.produced_count?.message : errors.good_count?.message}
                    className="font-mono text-right"
                  />
                  {!finishFirst && <div className="flex flex-col gap-1">
                    <span className="text-xs text-muted font-medium">
                      Reject count <span className="text-2xs">(auto-calculated)</span>
                    </span>
                    <div className="flex items-center h-8 px-3 rounded-md border border-default bg-elevated text-sm font-mono text-right tabular-nums">
                      {rejectCount}
                    </div>
                  </div>}
                  <div className="col-span-2 text-sm">
                    <span className="text-muted">HR shifts: </span>
                    {crew.data?.current?.members
                      .map((member) => `${member.name}: ${member.shift?.name ?? 'No HR shift'}`)
                      .join(' · ')}
                  </div>
                  <div className="col-span-2">
                    <Textarea
                      label="Recording on behalf reason"
                      required
                      {...register('recording_reason')}
                      error={errors.recording_reason?.message}
                    />
                  </div>
                  <div className="col-span-2">
                    <Textarea
                      label="Remarks"
                      rows={2}
                      {...register('remarks')}
                      error={errors.remarks?.message}
                    />
                  </div>
                </div>

                {!finishFirst && <div className="mt-4 border-t border-default pt-3">
                  <div className="flex items-center justify-between mb-2">
                    <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                      Defect breakdown
                    </div>
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      icon={<LuPlus size={14} />}
                      onClick={() => append({ defect_type_id: '', count: '' })}
                    >
                      Add defect
                    </Button>
                  </div>
                  {fields.length === 0 && (
                    <div className="text-xs text-muted">
                      No rejects — click "Add defect" to classify rejected units.
                    </div>
                  )}
                  {fields.map((f, i) => (
                    <div
                      key={f.id}
                      className="grid grid-cols-[minmax(0,1fr)_6rem_auto] items-end gap-2 mt-2"
                    >
                      <div className="min-w-0">
                        <Select
                          label={i === 0 ? 'Defect type' : ''}
                          {...register(`defects.${i}.defect_type_id`)}
                          error={errors.defects?.[i]?.defect_type_id?.message}
                        >
                          <option value="">Select…</option>
                          {(defects.data ?? []).map((d) => (
                            <option key={d.id} value={d.id}>
                              {d.code} — {d.name}
                            </option>
                          ))}
                        </Select>
                      </div>
                      <Input
                        label={i === 0 ? 'Count' : ''}
                        type="number"
                        min={1}
                        {...register(`defects.${i}.count`)}
                        error={errors.defects?.[i]?.count?.message}
                        className="w-24 font-mono text-right"
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        iconOnly
                        icon={<LuTrash2 size={14} />}
                        aria-label="Remove line"
                        onClick={() => remove(i)}
                        className="text-muted hover:text-danger-fg"
                      />
                    </div>
                  ))}
                </div>}
              </fieldset>
              {uncertain && pending && (
                <div role="alert" className="mt-4 space-y-2 text-sm">
                  <p>
                    Recording outcome unknown. Check the receipt or retry this same recording before
                    entering new counts.
                  </p>
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={submit.isPending || check.isPending}
                    onClick={() => check.mutate()}
                  >
                    Check receipt
                  </Button>
                  <Button
                    type="button"
                    disabled={submit.isPending || check.isPending}
                    onClick={() => submit.mutate(pending)}
                  >
                    Retry same recording
                  </Button>
                </div>
              )}
              <div className="mt-6 flex items-center justify-end gap-2 pt-3 border-t border-default">
                <Button type="button" variant="secondary" onClick={() => navigate(-1)}>
                  Back
                </Button>
                <Button
                  type="submit"
                  variant="primary"
                  disabled={outputBlocked || !!pending || isSubmitting || submit.isPending}
                  loading={submit.isPending}
                >
                  {submit.isPending ? 'Recording…' : 'Record'}
                </Button>
              </div>
            </Panel>
          </form>
        </div>

        <div className="min-w-0 space-y-4">
          <Panel title="Live cumulative" meta="updated via WebSocket">
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted">Produced</dt>
                <dd className="font-mono tabular-nums">{formatInt(cumulative.produced)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">{finishFirst ? 'QC accepted' : 'Good'}</dt>
                <dd className="font-mono tabular-nums text-success-fg">
                  {formatInt(cumulative.good)}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">{finishFirst ? 'Awaiting QC' : 'Reject'}</dt>
                <dd className="font-mono tabular-nums text-warning-fg">
                  {formatInt(cumulative.reject)}
                </dd>
              </div>
              {!finishFirst && <div className="flex justify-between">
                <dt className="text-muted">Scrap rate</dt>
                <dd className="font-mono tabular-nums">{Number(cumulative.scrap).toFixed(2)}%</dd>
              </div>}
              {wo.data && (
                <div className="flex justify-between border-t border-default pt-2 mt-2">
                  <dt className="text-muted">Target</dt>
                  <dd className="font-mono tabular-nums">{formatInt(wo.data.quantity_target)}</dd>
                </div>
              )}
            </dl>
          </Panel>
        </div>
      </div>
    </PageShell>
  );
}
