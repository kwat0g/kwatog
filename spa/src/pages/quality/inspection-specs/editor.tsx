/**
 * Sprint 7 — Task 59 — Inspection spec editor.
 *
 * Routed at /quality/inspection-specs/:productId where productId is the
 * product hash_id. The page calls inspectionSpecsApi.forProduct(productId)
 * to fetch the active spec (if any) and pre-fills the form. Saving POSTs
 * to /quality/inspection-specs (the upsert endpoint) — the service bumps
 * the version counter on every successful save.
 *
 * Special route /quality/inspection-specs/new lets the user pick a product
 * first; once picked, the page swaps to editor mode with that product
 * locked in.
 */
import { useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { LuArchiveRestore, LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { productsApi } from '@/api/crm/products';
import { inspectionSpecsApi, type SpcResult } from '@/api/quality/inspectionSpecs';
import { capabilityApi } from '@/api/quality/capability';
import { uomsApi } from '@/api/inventory/uoms';
import type { InspectionSpecRevision, UpsertInspectionSpecData } from '@/types/quality';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { Checkbox } from '@/components/ui/Checkbox';
import { usePermission } from '@/hooks/usePermission';

const signedDecimalPattern = /^-?\d{1,8}(?:\.\d{1,4})?$/;
const itemSchema = z.object({
 parameter_name: z.string().min(1, 'Parameter name is required').max(150),
 parameter_type: z.string().min(1, 'Parameter type is required'),
 unit_of_measure: z.string().max(20).optional().or(z.literal('')),
 nominal_value: z.string().refine((value) => value === '' || signedDecimalPattern.test(value), 'Use a signed decimal with up to 4 places').optional().or(z.literal('')),
 tolerance_min: z.string().refine((value) => value === '' || signedDecimalPattern.test(value), 'Use a signed decimal with up to 4 places').optional().or(z.literal('')),
 tolerance_max: z.string().refine((value) => value === '' || signedDecimalPattern.test(value), 'Use a signed decimal with up to 4 places').optional().or(z.literal('')),
 is_critical: z.boolean().optional(),
 notes: z.string().max(500).optional().or(z.literal('')),
}).superRefine((row, ctx) => {
 const nominal = row.nominal_value || null;
 const minimum = row.tolerance_min || null;
 const maximum = row.tolerance_max || null;
 const hasNumeric = Boolean(nominal || minimum || maximum);
 if (row.parameter_type === 'visual' && hasNumeric) {
  ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['parameter_type'], message: 'Visual parameters use manual pass/fail only' });
 }
 if (row.parameter_type === 'dimensional') {
  if (!nominal) ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['nominal_value'], message: 'Nominal is required for dimensional parameters' });
  if (!minimum) ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['tolerance_min'], message: 'Minimum tolerance is required' });
  if (!maximum) ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['tolerance_max'], message: 'Maximum tolerance is required' });
 }
 if (row.parameter_type === 'functional' && nominal && !minimum && !maximum) {
  ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['nominal_value'], message: 'A functional nominal needs a tolerance bound' });
 }
 if (minimum && maximum && Number(minimum) > Number(maximum)) {
  ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['tolerance_min'], message: 'Minimum must not exceed maximum' });
  ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['tolerance_max'], message: 'Maximum must not be below minimum' });
 }
 if (nominal && minimum && Number(nominal) < Number(minimum)) {
  ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['nominal_value'], message: 'Nominal must be within the tolerance window' });
 }
 if (nominal && maximum && Number(nominal) > Number(maximum)) {
  ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['nominal_value'], message: 'Nominal must be within the tolerance window' });
 }
});

const schema = z.object({
 product_id: z.string().min(1, 'Product is required'),
 notes: z.string().max(2000).optional().or(z.literal('')),
 items: z.array(itemSchema).min(1, 'Add at least one parameter'),
});

type FormValues = z.infer<typeof schema>;

export default function InspectionSpecEditorPage() {
 const { productId: productIdParam, specId: specIdParam } = useParams<{ productId: string; specId: string }>();
 const [searchParams] = useSearchParams();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const isNewMode = productIdParam === 'new';
 const isSpecDetailMode = Boolean(specIdParam);
 const [pickedProductId, setPickedProductId] = useState('');
 const specDetail = useQuery({
  queryKey: ['quality', 'inspection-specs', 'show', specIdParam],
  queryFn: () => inspectionSpecsApi.show(specIdParam as string),
  enabled: isSpecDetailMode,
 });
 const productId = isSpecDetailMode
  ? (specDetail.data?.product?.id ?? '')
  : (isNewMode ? pickedProductId : (productIdParam ?? ''));

 const products = useQuery({
 queryKey: ['crm', 'products', 'lookup'],
 queryFn: () => productsApi.list({ per_page: 100, is_active: 'true' }),
 });
 const parameterOptions = useQuery({
 queryKey: ['quality', 'inspection-specs', 'options'],
 queryFn: () => inspectionSpecsApi.options(),
 });
 const parameterTypes = parameterOptions.data?.parameter_types ?? [];
 const defaultParameterType = parameterTypes[0]?.value ?? '';
 const existing = useQuery({
 queryKey: ['quality', 'inspection-specs', 'for-product', productId],
 queryFn: () => inspectionSpecsApi.forProduct(productId),
 enabled: !!productId && !isSpecDetailMode,
 });

 const loadedSpec = isSpecDetailMode ? specDetail.data : existing.data;
 const specId = loadedSpec?.id ?? specIdParam ?? '';
 const isArchived = Boolean(loadedSpec && (!loadedSpec.is_active || loadedSpec.deleted_at));
 const readOnly = !can('quality.specs.manage') || isArchived;
 const revisions = useQuery({
  queryKey: ['quality', 'inspection-specs', 'revisions', specId],
  queryFn: () => inspectionSpecsApi.revisions(specId),
  enabled: isSpecDetailMode && !!specId,
 });
 const [selectedRevisionId, setSelectedRevisionId] = useState<string | null>(searchParams.get('revision'));
 const selectedRevision: InspectionSpecRevision | undefined = revisions.data?.find((revision) => revision.id === selectedRevisionId)
  ?? revisions.data?.find((revision) => revision.is_current)
  ?? revisions.data?.[0];
 useEffect(() => {
  if (!revisions.data?.length) return;
  if (!selectedRevisionId || !revisions.data.some((revision) => revision.id === selectedRevisionId)) {
   setSelectedRevisionId(selectedRevision?.id ?? null);
  }
 }, [revisions.data, selectedRevision?.id, selectedRevisionId]);
 const spcData = useQuery({
 queryKey: ['quality', 'inspection-specs', 'spc', specId],
 queryFn: () => inspectionSpecsApi.spc(specId),
 enabled: !!specId,
 });
 const { data: spcOptions } = useQuery({
 queryKey: ['quality', 'spc', 'options'],
 queryFn: capabilityApi.options,
 staleTime: 300_000,
 });
 const { data: uoms = [] } = useQuery({
   queryKey: ['inventory', 'uoms'],
   queryFn: uomsApi.list,
   staleTime: 300_000
 });
 const cpkThresholds = spcOptions?.capability_thresholds;

 const {
 register, control, handleSubmit, reset, setError,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 product_id: productId,
 notes: '',
 items: [{ parameter_name: '', parameter_type: defaultParameterType, unit_of_measure: '', nominal_value: '', tolerance_min: '', tolerance_max: '', is_critical: false, notes: '' }],
 },
 });
 const { fields, append, remove } = useFieldArray({ control, name: 'items' });

 // Pre-fill once the existing spec query resolves (or once user picks a product in new mode).
 useEffect(() => {
 if (!productId) return;
 if (isSpecDetailMode && specDetail.data === undefined) return;
 if (!isSpecDetailMode && existing.data === undefined) return;
 if (loadedSpec) {
 reset({
 product_id: productId,
 notes: loadedSpec.notes ?? '',
 items: (loadedSpec.items ?? []).map((it) => ({
 parameter_name: it.parameter_name,
 parameter_type: it.parameter_type,
 unit_of_measure: it.unit_of_measure ?? '',
 nominal_value: it.nominal_value ?? '',
 tolerance_min: it.tolerance_min ?? '',
 tolerance_max: it.tolerance_max ?? '',
 is_critical: it.is_critical,
 notes: it.notes ?? '',
 })),
 });
 } else {
 reset({
 product_id: productId,
 notes: '',
 items: [{ parameter_name: '', parameter_type: defaultParameterType, unit_of_measure: '', nominal_value: '', tolerance_min: '', tolerance_max: '', is_critical: false, notes: '' }],
 });
 }
 }, [productId, loadedSpec, isSpecDetailMode, specDetail.data, existing.data, reset, defaultParameterType]);

 const upsert = useMutation({
 mutationFn: (values: FormValues) => {
 const payload: UpsertInspectionSpecData = {
 product_id: values.product_id,
 notes: values.notes || undefined,
 items: values.items.map((row, i) => ({
 parameter_name: row.parameter_name,
 parameter_type: row.parameter_type as UpsertInspectionSpecData['items'][number]['parameter_type'],
 unit_of_measure: row.unit_of_measure || undefined,
 nominal_value: row.nominal_value || undefined,
 tolerance_min: row.tolerance_min || undefined,
 tolerance_max: row.tolerance_max || undefined,
 is_critical: row.is_critical ?? false,
 sort_order: i,
 notes: row.notes || undefined,
 })),
 };
 return inspectionSpecsApi.upsert(payload);
 },
 onSuccess: (spec) => {
 qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs'] });
 qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs', 'for-product', productId] });
 qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs', 'spc'] });
 toast.success(`Spec v${spec.version} saved.`);
 navigate(isSpecDetailMode ? `/quality/inspection-specs/spec/${spec.id}` : `/quality/inspection-specs/${productId}`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
 setError(field as never, { type: 'server', message: msgs[0] });
 });
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to save spec.');
 }
 },
 });

 const restore = useMutation({
  mutationFn: () => inspectionSpecsApi.restore(specId),
  onSuccess: () => {
   qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs'] });
   qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs', 'show', specId] });
   toast.success('Inspection spec restored');
  },
  onError: () => toast.error('Failed to restore inspection spec'),
 });

 const productLabel = useMemo(() => {
 const p = loadedSpec?.product ?? products.data?.data?.find((pp: { id: string; part_number: string; name: string }) => pp.id === productId);
 return p ? `${p.part_number} — ${p.name}` : '';
 }, [loadedSpec?.product, products.data, productId]);

 // ── New-spec mode without picked product yet
 if (isNewMode && !can('quality.specs.manage')) {
 return (
  <div>
   <PageHeader title="New inspection spec" backTo="/quality/inspection-specs" backLabel="Inspection specs" />
   <div className="max-w-2xl mx-auto px-5 py-4">
    <EmptyState icon="lock" title="Authoring permission required" description="You can view inspection specs, but only quality managers can create or revise them." />
   </div>
  </div>
 );
 }

 if (isNewMode && !pickedProductId) {
 return (
 <div>
 <PageHeader title="New inspection spec" backTo="/quality/inspection-specs" backLabel="Inspection specs"
 />
 <div className="max-w-2xl mx-auto px-5 py-4 space-y-4">
 <Select
 label="Product"
 required
 value={pickedProductId}
 onChange={(e) => setPickedProductId(e.target.value)}
 >
 <option value="">Select product…</option>
 {products.data?.data?.map((p: { id: string; part_number: string; name: string }) => (
 <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
 ))}
 </Select>
 <p className="text-xs text-muted">
 Pick a product to author or replace its inspection spec. Each product carries one active spec; saving creates a new version.
 </p>
 </div>
 </div>
 );
 }

 // ── Loading existing spec
 if (productId && (existing.isLoading || specDetail.isLoading)) {
 return (
 <div>
 <PageHeader title="Inspection spec" backTo="/quality/inspection-specs" backLabel="Inspection specs"
 />
 <SkeletonForm />
 </div>
 );
 }

 // ── Error loading existing spec
 if (existing.isError || specDetail.isError) {
 return (
 <div>
 <PageHeader title="Inspection spec" backTo="/quality/inspection-specs" backLabel="Inspection specs"
 />
 <EmptyState
 icon="alert-circle"
 title="Failed to load spec"
 action={<Button variant="secondary" onClick={() => (isSpecDetailMode ? specDetail.refetch() : existing.refetch())}>Retry</Button>}
 />
 </div>
 );
 }

 return (
 <div>
 <PageHeader
 title={
 <div className="flex items-center gap-3">
 <span>{productLabel || 'Inspection spec'}</span>
 {loadedSpec && <Chip variant={loadedSpec.is_active ? 'success' : 'neutral'}>{loadedSpec.is_active ? `v${loadedSpec.version}` : 'Archived'}</Chip>}
 {!loadedSpec && <Chip variant="info">New</Chip>}
 </div>
 }
 backTo="/quality/inspection-specs"
 backLabel="Inspection specs"
 />
 <form
 onSubmit={readOnly ? (e) => e.preventDefault() : handleSubmit((v) => upsert.mutate(v), onFormInvalid<FormValues>())}
 className="max-w-5xl mx-auto px-5 py-4"
 >
 <input type="hidden" {...register('product_id')} value={productId} />

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Notes</legend>
 <Textarea
 rows={2}
 {...register('notes')}
 readOnly={readOnly}
 error={errors.notes?.message}
 placeholder="Optional context for this revision."
 />
 </fieldset>

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Parameters</legend>
 <div className="overflow-x-auto rounded-md border border-default">
 <table className={tableCls + ' min-w-[960px]'}>
 <thead>
 <tr className={theadTrCls}>
 <Th className="w-1/4">Parameter</Th>
 <Th>Type</Th>
 <Th>UOM</Th>
 <Th align="right">Nominal</Th>
 <Th align="right">Min</Th>
 <Th align="right">Max</Th>
 <Th>Notes</Th>
 <Th align="center">Critical?</Th>
 <Th />
 </tr>
 </thead>
 <tbody>
 {fields.map((field, i) => (
 <tr key={field.id} className={trCls}>
 <Td>
 <Input
 {...register(`items.${i}.parameter_name` as const)}
 aria-label={'Parameter ' + (i + 1) + ' name'}
 error={errors.items?.[i]?.parameter_name?.message}
 placeholder="Measurement name"
 readOnly={readOnly}
 />
 </Td>
 <Td>
 <Select
 {...register(`items.${i}.parameter_type` as const)}
 aria-label={'Parameter ' + (i + 1) + ' type'}
 error={errors.items?.[i]?.parameter_type?.message}
 disabled={readOnly}
 >
 {parameterTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
 </Select>
 </Td>
 <Td>
 <Select
 {...register(`items.${i}.unit_of_measure` as const)}
 aria-label={'Parameter ' + (i + 1) + ' unit of measure'}
 error={errors.items?.[i]?.unit_of_measure?.message}
 className="font-mono"
 disabled={readOnly}
 >
 <option value="">—</option>
 {uoms.map((u) => <option key={u.id} value={u.code}>{u.code}</option>)}
 </Select>
 </Td>
 <Td align="right" mono>
 <Input
 {...register(`items.${i}.nominal_value` as const)}
 aria-label={'Parameter ' + (i + 1) + ' nominal value'}
 error={errors.items?.[i]?.nominal_value?.message}
 placeholder="0.0000"
 className="font-mono text-right"
 readOnly={readOnly}
 />
 </Td>
 <Td align="right" mono>
 <Input
 {...register(`items.${i}.tolerance_min` as const)}
 aria-label={'Parameter ' + (i + 1) + ' minimum tolerance'}
 error={errors.items?.[i]?.tolerance_min?.message}
 placeholder="0.0000"
 className="font-mono text-right"
 readOnly={readOnly}
 />
 </Td>
 <Td align="right" mono>
 <Input
 {...register(`items.${i}.tolerance_max` as const)}
 aria-label={'Parameter ' + (i + 1) + ' maximum tolerance'}
 error={errors.items?.[i]?.tolerance_max?.message}
 placeholder="0.0000"
 className="font-mono text-right"
 readOnly={readOnly}
 />
 </Td>
 <Td>
 <Input
  {...register(`items.${i}.notes` as const)}
  aria-label={'Parameter ' + (i + 1) + ' notes'}
  error={errors.items?.[i]?.notes?.message}
  placeholder="Optional note"
  readOnly={readOnly}
 />
 </Td>
 <Td align="center">
 <Checkbox
 aria-label={'Parameter ' + (i + 1) + ' critical characteristic'}
 {...register(`items.${i}.is_critical` as const)}
 disabled={readOnly}
 />
 </Td>
 <Td align="right" mono>
 {!readOnly && <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuTrash2 size={14} />}
 aria-label="Remove parameter"
 onClick={() => remove(i)}
 disabled={fields.length === 1}
 className="text-muted hover:text-danger-fg"
 />}
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>

 {!readOnly && <div className="mt-3">
 <Button
 type="button"
 variant="secondary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => append({ parameter_name: '', parameter_type: defaultParameterType, unit_of_measure: '', nominal_value: '', tolerance_min: '', tolerance_max: '', is_critical: false, notes: '' })}
 >
 Add parameter
 </Button>
 </div>}

 {errors.items?.message && <p className="mt-2 text-xs text-danger-fg">{errors.items.message as string}</p>}
 </fieldset>

 {isSpecDetailMode && loadedSpec && (
 <fieldset className="mb-8">
  <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Revision history</legend>
  <p className="mb-3 text-xs text-muted">The authoring form above shows the current revision. Select a prior revision below to reconstruct the exact definition used by historical inspection evidence.</p>
  {revisions.isLoading && <div className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted" role="status">Loading revision history…</div>}
  {revisions.isError && <div className="rounded-md border border-danger-border bg-danger-bg px-3 py-3 text-sm text-danger-fg" role="alert">
   <div>Revision history could not be loaded.</div>
   <Button className="mt-2" variant="secondary" size="sm" onClick={() => void revisions.refetch()}>Retry history</Button>
  </div>}
  {!revisions.isLoading && !revisions.isError && revisions.data && revisions.data.length > 0 && (
  <>
   <Select
    label="Revision to inspect"
    value={selectedRevision?.id ?? ''}
    onChange={(event) => setSelectedRevisionId(event.target.value)}
   >
    {revisions.data.map((revision) => (
     <option key={revision.id} value={revision.id}>
      v{revision.version}{revision.is_current ? ' — current' : ''}
     </option>
    ))}
   </Select>
   {selectedRevision && (
   <section className="mt-3 space-y-3" aria-label={`Inspection spec revision ${selectedRevision.version}`}>
    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
     <span>Created {selectedRevision.created_at?.slice(0, 10) ?? '—'}</span>
     <span>By {selectedRevision.creator?.name ?? 'Unknown actor'}</span>
     {selectedRevision.is_current && <Chip variant="info">Current revision</Chip>}
    </div>
    {selectedRevision.notes && <p className="text-sm text-muted">{selectedRevision.notes}</p>}
    <div className="overflow-x-auto rounded-md border border-default">
     <table className={tableCls + ' min-w-[760px]'}>
      <thead>
       <tr className={theadTrCls}>
        <Th>Parameter</Th>
        <Th>Type</Th>
        <Th>UOM</Th>
        <Th align="right">Nominal</Th>
        <Th align="right">Min</Th>
        <Th align="right">Max</Th>
        <Th>Notes</Th>
       </tr>
      </thead>
      <tbody>
       {selectedRevision.items.map((item) => (
        <tr key={item.id} className={trCls}>
         <Td>{item.parameter_name}</Td>
         <Td>{item.parameter_type_label ?? item.parameter_type}</Td>
         <Td className="font-mono">{item.unit_of_measure ?? '—'}</Td>
         <Td align="right" mono>{item.nominal_value ?? '—'}</Td>
         <Td align="right" mono>{item.tolerance_min ?? '—'}</Td>
         <Td align="right" mono>{item.tolerance_max ?? '—'}</Td>
         <Td>{item.notes ?? '—'}</Td>
        </tr>
       ))}
      </tbody>
     </table>
    </div>
   </section>
   )}
  </>
  )}
 </fieldset>
 )}

 {specId && (
 <div className="mb-8">
 <h3 className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
 Process Capability (SPC)
 </h3>
 {spcData.isLoading && <div className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted" role="status">Loading current-revision SPC data…</div>}
 {spcData.isError && <div className="rounded-md border border-danger-border bg-danger-bg px-3 py-3 text-sm text-danger-fg" role="alert">
  <div>SPC data could not be loaded.</div>
  <Button className="mt-2" variant="secondary" size="sm" onClick={() => void spcData.refetch()}>Retry SPC</Button>
 </div>}
 {!spcData.isLoading && !spcData.isError && spcData.data && cpkThresholds && Object.keys(spcData.data.data).length === 0 && (
 <div className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted" role="status">
  No completed inspection readings from the current revision meet the minimum sample count and measurable-variation requirement for SPC yet.
 </div>
 )}
 {!spcData.isLoading && !spcData.isError && spcData.data && cpkThresholds && Object.keys(spcData.data.data).length > 0 && (
 <div className="overflow-x-auto rounded-md border border-default">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Parameter</Th>
 <Th align="right" className="font-mono">Cp</Th>
 <Th align="right" className="font-mono">Cpk</Th>
 <Th align="right" className="font-mono">Mean</Th>
 <Th align="right" className="font-mono">σ</Th>
 <Th align="right">n</Th>
 </tr>
 </thead>
 <tbody>
 {Object.entries(spcData.data.data).map(([id, s]) => {
 const item = s as SpcResult;
 const cpColor = item.cp >= cpkThresholds.ongoing ? 'text-success-fg' : item.cp >= cpkThresholds.action ? 'text-warning-fg' : 'text-danger-fg';
 const cpkColor = item.cpk >= cpkThresholds.ongoing ? 'text-success-fg' : item.cpk >= cpkThresholds.action ? 'text-warning-fg' : 'text-danger-fg';
 return (
 <tr key={id} className={trCls}>
 <Td>
 {item.parameter_name}
 {item.unit && <span className="ml-1 text-muted">({item.unit})</span>}
 </Td>
 <Td align="right" mono className={cpColor}>
 {item.cp.toFixed(3)}
 </Td>
 <Td align="right" mono className={cpkColor}>
 {item.cpk.toFixed(3)}
 </Td>
 <Td align="right" mono>
 {item.mean.toFixed(4)}
 </Td>
 <Td align="right" mono>
 {item.std_dev.toFixed(4)}
 </Td>
 <Td align="right" mono className="text-muted">
 {item.sample_count}
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </div>
 )}
 {spcData.data?.meta?.revision_version && <p className="mt-2 text-2xs text-muted">Population: current revision v{spcData.data.meta.revision_version} only.</p>}
 {spcData.data && !cpkThresholds && <div className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted" role="status">Loading SPC thresholds…</div>}
 <p className="mt-2 text-2xs text-muted">
 {cpkThresholds ? <>Cp / Cpk ≥ {cpkThresholds.ongoing.toFixed(2)} = capable · {cpkThresholds.action.toFixed(1)}–{cpkThresholds.ongoing.toFixed(2)} = marginal · &lt;{cpkThresholds.action.toFixed(1)} = not capable · Minimum {cpkThresholds.minimum_samples} measurements required per parameter</> : null}
 </p>
 </div>
 )}

 <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
 <Button type="button" variant="secondary" onClick={() => navigate('/quality/inspection-specs')}>
 Cancel
 </Button>
 {isArchived && can('quality.specs.manage') && (
  <Button
   type="button"
   variant="secondary"
   icon={<LuArchiveRestore size={14} />}
   onClick={() => restore.mutate()}
   disabled={restore.isPending}
  >
   {restore.isPending ? 'Restoring…' : 'Restore spec'}
  </Button>
 )}
 {!readOnly ? (
  <Button
   type="submit"
   variant="primary"
   disabled={isSubmitting || upsert.isPending}
   loading={upsert.isPending}
  >
   {upsert.isPending ? 'Saving…' : (loadedSpec ? 'Save new version' : 'Create spec')}
  </Button>
 ) : (
  <p className="text-xs text-muted" role="status">
   {isArchived ? 'Archived specs are read-only until restored.' : 'View-only access: authoring controls are disabled.'}
  </p>
 )}
 </div>
 </form>
 </div>
 );
}
