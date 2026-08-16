/**
 * Sprint 7 — Task 60 — Inspection detail / measurement-recording page.
 *
 * The page groups the seeded measurements by sample_index. The inspector
 * fills in measured_value (auto pass/fail for dimensional/functional via
 * tolerance window) or toggles is_pass (visual checks). Save patches the
 * batch; Complete finalises the inspection (passed/failed by AQL plan).
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { LuCheck, LuBan, LuSave, LuFileDown } from '@/lib/icons';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { inspectionsApi } from '@/api/quality/inspections';
import { analyticsApi, type SpcCapabilityItem } from '@/api/quality/analytics';
import { capabilityApi } from '@/api/quality/capability';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { usePermission } from '@/hooks/usePermission';
import type { InspectionMeasurement, InspectionStatus } from '@/types/quality';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { Input } from '@/components/ui/Input';
import { SpecToleranceBar } from '@/components/ui/SpecToleranceBar';
import { cn } from '@/lib/cn';

const STATUS_CHIP: Record<InspectionStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
 draft: 'neutral',
 in_progress: 'info',
 passed: 'success',
 failed: 'danger',
 cancelled: 'neutral',
};

interface RowDraft {
 id: string;
 measured_value: string; // keep as string to allow empty input
 is_pass: boolean | null;
 notes: string;
 dirty: boolean;
}

export default function InspectionDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [drafts, setDrafts] = useState<Record<string, RowDraft>>({});
 const [confirmComplete, setConfirmComplete] = useState(false);
 const [confirmCancel, setConfirmCancel] = useState(false);
 const [cocLoading, setCocLoading] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['quality', 'inspections', id],
 queryFn: () => inspectionsApi.show(id),
 enabled: Boolean(id),
 placeholderData: (prev) => prev,
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
 const { data: spcOptions } = useQuery({
 queryKey: ['quality', 'spc', 'options'],
 queryFn: capabilityApi.options,
 staleTime: 300_000,
 });
 const statusLabel = inspectionOptions?.statuses.find((option) => option.value === data?.status)?.label;
 const stageLabel = inspectionOptions?.stages.find((option) => option.value === data?.stage)?.label;
 const measurementResultLabels = new Map((inspectionOptions?.measurement_results ?? []).map((option) => [option.value, option.label]));
 const cpkThresholds = spcOptions?.capability_thresholds;

 const spc = useQuery({
 queryKey: ['quality', 'spc', data?.spec?.id],
 queryFn: () => analyticsApi.spcForSpec(data!.spec!.id),
 enabled: Boolean(data?.spec?.id),
 staleTime: 300_000,
 });

 // Seed local drafts whenever the server payload arrives.
 useEffect(() => {
 if (!data?.measurements) return;
 setDrafts((existing) => {
 const next: Record<string, RowDraft> = {};
 for (const m of data.measurements!) {
 next[m.id] = existing[m.id]?.dirty
 ? existing[m.id]
 : {
 id: m.id,
 measured_value: m.measured_value === null ? '' : String(m.measured_value),
 is_pass: m.is_pass,
 notes: m.notes ?? '',
 dirty: false,
 };
 }
 return next;
 });
 }, [data]);

 const save = useMutation({
 mutationFn: () => {
 const dirty = Object.values(drafts).filter((d) => d.dirty);
 return inspectionsApi.recordMeasurements(id, {
 measurements: dirty.map((d) => ({
 id: d.id,
 measured_value: d.measured_value === '' ? null : Number(d.measured_value),
 is_pass: d.is_pass,
 notes: d.notes || null,
 })),
 });
 },
 onSuccess: () => {
 toast.success('Measurements saved');
 qc.invalidateQueries({ queryKey: ['quality', 'inspections', id] });
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Save failed');
 },
 });

 const complete = useMutation({
 mutationFn: () => inspectionsApi.complete(id),
 onSuccess: (insp) => {
 toast.success(`Inspection ${insp.status === 'passed' ? 'PASSED' : 'FAILED'}`);
 qc.invalidateQueries({ queryKey: ['quality', 'inspections', id] });
 setConfirmComplete(false);
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Could not complete');
 },
 });

 const cancel = useMutation({
 mutationFn: (reason: string) => inspectionsApi.cancel(id, reason),
 onSuccess: () => {
 toast.success('Inspection cancelled');
 qc.invalidateQueries({ queryKey: ['quality', 'inspections', id] });
 setConfirmCancel(false);
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Could not cancel');
 },
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
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 </div>
 );
 }

 // Group measurements by sample_index for a per-sample card.
 const grouped: Record<number, InspectionMeasurement[]> = {};
 for (const m of data.measurements ?? []) {
 (grouped[m.sample_index] ??= []).push(m);
 }
 const sampleIndices = Object.keys(grouped).map(Number).sort((a, b) => a - b);

 const isTerminal = ['passed', 'failed', 'cancelled'].includes(data.status);
 const dirtyCount = Object.values(drafts).filter((d) => d.dirty).length;
 const unresolvedCount = (data.measurements ?? []).filter((m) => m.is_pass === null).length;

 const updateDraft = (mId: string, patch: Partial<RowDraft>) => {
 setDrafts((s) => ({ ...s, [mId]: { ...s[mId], ...patch, dirty: true } }));
 };

 return (
 <div>
 <PageHeader
 title={
 <span>
 {data.inspection_number}
 <Chip variant={STATUS_CHIP[data.status]} className="ml-3">
 {data.status_label ?? statusLabel ?? data.status}
 </Chip>
 </span>
 }
 subtitle={
 data.product
 ? `${data.product.part_number} — ${data.product.name} (${stageLabel ?? data.stage})`
 : data.item
 ? `${data.item.code} — ${data.item.name} (${stageLabel ?? data.stage})`
 : stageLabel ?? data.stage
 }
 actions={
 <div className="flex items-center gap-2">
 {!isTerminal && can('quality.inspections.manage') && (
 <>
 <Button
 variant="secondary"
 size="sm"
 icon={<LuSave size={14} />}
 loading={save.isPending}
 disabled={dirtyCount === 0}
 onClick={() => save.mutate()}
 >
 Save ({dirtyCount})
 </Button>
 <Button
 variant="primary"
 size="sm"
 icon={<LuCheck size={14} />}
 disabled={unresolvedCount > 0}
 onClick={() => setConfirmComplete(true)}
 >
 Complete
 </Button>
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
 {/* CoC available for passed outgoing inspections */}
 {data.stage === 'outgoing' && data.status === 'passed' && (
 <Button
 variant="secondary"
 size="sm"
 icon={<LuFileDown size={14} />}
 loading={cocLoading}
 onClick={async () => {
 setCocLoading(true);
 try {
 await inspectionsApi.generateCoC(id);
 } catch {
 toast.error('Could not generate CoC — try again.');
 } finally {
 setCocLoading(false);
 }
 }}
 >
 Certificate of Conformance
 </Button>
 )}
 </div>
 }
 />

 {inspectionChain && (
 <div className="px-5 py-3 border-b border-default">
 <ChainHeader steps={inspectionChain} />
 </div>
 )}

 <div className="px-5 grid grid-cols-3 gap-4">
 <div className="col-span-2 space-y-4">
 <Panel title="Sample plan">
 <dl className="grid grid-cols-4 gap-x-4 gap-y-3 text-sm">
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Stage</dt>
 <dd className="font-mono">{data.stage_label ?? data.stage}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Batch</dt>
 <dd className="font-mono tabular-nums">{data.batch_quantity}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Sample</dt>
 <dd className="font-mono tabular-nums">
 {data.sample_size}
 {data.aql_code ? <span className="ml-2 text-muted">[{data.aql_code}]</span> : null}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Ac / Re</dt>
 <dd className="font-mono tabular-nums">
 {data.accept_count} / {data.reject_count}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Defects</dt>
 <dd
 className={`font-mono tabular-nums ${
 data.defect_count > data.accept_count ? 'text-danger-fg' : ''
 }`}
 >
 {data.defect_count}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Inspector</dt>
 <dd>{data.inspector?.name ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Started</dt>
 <dd className="font-mono tabular-nums">{data.started_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted">Completed</dt>
 <dd className="font-mono tabular-nums">{data.completed_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
 </div>
 </dl>
 </Panel>

 {sampleIndices.map((idx) => (
 <Panel
 key={idx}
 title={`Sample #${idx}`}
 meta={`${grouped[idx].length} parameter${grouped[idx].length === 1 ? '' : 's'}`}
 noPadding
 >
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>
 Parameter
 </Th>
 <Th align="right">
 Nominal
 </Th>
 <Th align="right">
 Tolerance
 </Th>
 <Th align="right">
 Measured
 </Th>
 <Th align="center">
 Pass
 </Th>
 </tr>
 </thead>
 <tbody>
 {grouped[idx].map((m) => {
 const draft = drafts[m.id];
 if (!draft) return null;
 const numericTol =
 m.tolerance_min !== null || m.tolerance_max !== null
 ? `${m.tolerance_min ?? '−∞'} … ${m.tolerance_max ?? '+∞'}`
 : '—';
 return (
 <tr key={m.id} className={trCls}>
 <Td>
 <div className="flex items-center gap-2">
 <span>{m.parameter_name}</span>
 {m.is_critical && <Chip variant="danger">Critical</Chip>}
 <span className="text-2xs uppercase text-muted">{m.parameter_type_label ?? m.parameter_type}</span>
 </div>
 </Td>
 <Td align="right" mono>
 {m.nominal_value ?? '—'} {m.unit_of_measure ?? ''}
 </Td>
 <Td align="right" mono>{numericTol}</Td>
 <Td align="right" mono>
 {m.parameter_type === 'visual' ? (
 <span className="text-muted text-2xs">N/A</span>
 ) : (
 <div className="flex flex-col items-end gap-1">
 <Input
 fieldSize="sm"
 type="number"
 step="any"
 disabled={isTerminal}
 aria-label="Measured value"
 containerClassName="inline-flex w-24"
 className="text-right font-mono tabular-nums"
 value={draft.measured_value}
 onChange={(e) =>
 updateDraft(m.id, { measured_value: e.target.value })
 }
 />
 <SpecToleranceBar
 nominal={m.nominal_value}
 min={m.tolerance_min}
 max={m.tolerance_max}
 value={draft.measured_value}
 unit={m.unit_of_measure ?? ''}
 className="mt-0.5"
 />
 </div>
 )}
 </Td>
 <Td align="center">
 {m.parameter_type === 'visual' ? (
 <Select
 fieldSize="sm"
 containerClassName="inline-flex w-24"
 aria-label="Visual result"
 disabled={isTerminal}
 value={draft.is_pass === null ? '' : draft.is_pass ? 'pass' : 'fail'}
 onChange={(e) =>
 updateDraft(m.id, {
 is_pass:
 e.target.value === '' ? null : e.target.value === 'pass',
 })
 }
 >
 <option value="">—</option>
 {(inspectionOptions?.measurement_results ?? []).map((option) => (
 <option key={option.value} value={option.value}>{option.label}</option>
 ))}
 </Select>
 ) : draft.is_pass === null ? (
 <span className="text-muted text-2xs">—</span>
 ) : draft.is_pass ? (
 <Chip variant="success">{measurementResultLabels.get('pass') ?? 'Pass'}</Chip>
 ) : (
 <Chip variant="danger">{measurementResultLabels.get('fail') ?? 'Fail'}</Chip>
 )}
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </Panel>
 ))}
 </div>

 <div className="space-y-4">
 <Panel title="Status">
 {isTerminal ? (
 <p className="text-sm">
 Inspection finalised on{' '}
 <span className="font-mono tabular-nums">
 {data.completed_at?.slice(0, 16).replace('T', ' ')}
 </span>
 .
 </p>
 ) : unresolvedCount > 0 ? (
 <p className="text-sm text-muted">
 {unresolvedCount} measurement{unresolvedCount === 1 ? '' : 's'} still pending. Complete is disabled
 until every sampled unit has a pass/fail recorded.
 </p>
 ) : data.defect_count > data.accept_count ? (
 <p className="text-sm text-danger-fg">
 Defects ({data.defect_count}) exceed Ac ({data.accept_count}). Completing now will mark this
 inspection as <strong>failed</strong>.
 </p>
 ) : (
 <p className="text-sm text-success-fg">
 All measurements recorded within tolerance. Completing will mark this inspection as{' '}
 <strong>passed</strong>.
 </p>
 )}
 </Panel>

 {data.notes && (
 <Panel title="Notes">
 <p className="whitespace-pre-line text-sm">{data.notes}</p>
 </Panel>
 )}

 {/* SPC Capability indices for this spec */}
 {data.spec && spc.data && cpkThresholds && Object.keys(spc.data).length > 0 && (
 <Panel title="SPC capability indices" meta={`${Object.keys(spc.data).length} dimension${Object.keys(spc.data).length === 1 ? '' : 's'}`}>
 <table className={cn(tableCls, 'mt-2')}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Dimension</Th>
 <Th align="right">Mean</Th>
 <Th align="right">Cp</Th>
 <Th align="right">Cpk</Th>
 <Th align="right">n</Th>
 <Th>Rating</Th>
 </tr>
 </thead>
 <tbody>
 {Object.values(spc.data).map((item: SpcCapabilityItem) => {
 const variant = item.cpk >= cpkThresholds.launch ? 'success' : item.cpk >= cpkThresholds.ongoing ? 'info' : item.cpk >= cpkThresholds.action ? 'warning' : 'danger';
 const label = item.cpk >= cpkThresholds.launch ? 'Excellent' : item.cpk >= cpkThresholds.ongoing ? 'Capable' : item.cpk >= cpkThresholds.action ? 'Marginal' : 'Not capable';
 return (
 <tr key={item.parameter_name} className={trCls}>
 <Td>{item.parameter_name}{item.unit ? ` (${item.unit})` : ''}</Td>
 <Td align="right" mono>{item.mean.toFixed(3)}</Td>
 <Td align="right" mono>{item.cp.toFixed(2)}</Td>
 <Td align="right" mono className="font-medium">{item.cpk.toFixed(2)}</Td>
 <Td align="right" mono className="text-muted">{item.sample_count}</Td>
 <Td><Chip variant={variant}>{label}</Chip></Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 <p className="text-2xs text-muted mt-2">
 Capability targets: Cpk ≥ {cpkThresholds.ongoing.toFixed(2)} ongoing · ≥ {cpkThresholds.launch.toFixed(2)} new product launch
 </p>
 </Panel>
 )}

 {/* Sprint 7 audit fix: LinkedRecords (Order-to-Cash chain) */}
 {data.product && (
 <Panel title="Linked records">
 <LinkedRecords
 groups={[
 {
 label: 'Product',
 items: [{
 id: `${data.product.part_number} — ${data.product.name}`,
 href: `/crm/products/${data.product.id}`,
 }],
 },
 ...(data.spec ? [{
 label: 'Inspection spec',
 items: [{
 id: `v${data.spec.version}`,
 href: `/quality/inspection-specs/${data.product.id}`,
 meta: data.spec.is_active ? 'active' : 'archived',
 }],
 }] : []),
 ]}
 />
 </Panel>
 )}
 <Panel title="Actions">
 <Link to="/quality/inspections" className="text-xs text-accent hover:underline">
 ← Back to inspections
 </Link>
 </Panel>
 </div>
 </div>

 <ConfirmDialog
 isOpen={confirmComplete}
 title="Complete inspection?"
 description="This will finalise the inspection and lock all measurements. The system will compute pass/fail using the AQL plan."
 confirmLabel="Complete"
 onConfirm={() => complete.mutate()}
 onClose={() => setConfirmComplete(false)}
 pending={complete.isPending}
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
 </div>
 );
}
