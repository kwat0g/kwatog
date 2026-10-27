/**
 * Task 12 — Routing editor page (create + view + edit).
 *
 * With `:id` the page loads a published routing. Three states, all served by
 * this one component:
 *
 *  - create              — blank form, requires `production.routings.manage`
 *  - edit (active)       — saving PUBLISHES A NEW VERSION; the version being
 *                          edited is never mutated, because work orders hold a
 *                          foreign key to its operations
 *  - read-only           — no `production.routings.manage`, or the version is
 *                          superseded. The route is view-gated to match
 *                          `GET /routings/{id}`, so a view-only role can read
 *                          the process plan it is running without being shown
 *                          actions the API would deny (M052 F-02).
 *
 * Uses React Hook Form with useFieldArray for the operations list.
 * Follows the BOM create page pattern for dynamic sub-item rows.
 */
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { LuCheck, LuCopy, LuPlus, LuTrash2, LuGripVertical } from '@/lib/icons';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { FormGrid } from '@/components/layout/ContentLayouts';
import { productsApi } from '@/api/crm/products';
import { machinesApi } from '@/api/mrp/machines';
import { moldsApi } from '@/api/mrp/molds';
import { routingsApi } from '@/api/production/routings';
import { usePermission } from '@/hooks/usePermission';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

// ─── Schema ──────────────────────────────────────────────────────

const operationSchema = z.object({
 sequence: z.string().regex(/^\d+$/, 'Required').refine((v) => Number(v) > 0, 'Must be > 0'),
 operation_name: z.string().min(1, 'Operation name is required').max(100),
 work_center: z.string().max(100).optional().or(z.literal('')),
 machine_id: z.string().optional().or(z.literal('')),
 mold_id: z.string().optional().or(z.literal('')),
 setup_time_minutes: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative decimal').optional().or(z.literal('')),
 cycle_time_minutes: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Cycle time is required').refine((v) => Number(v) > 0, 'Must be > 0'),
 labor_rate_per_hour: z.string().regex(/^\d+(\.\d{1,4})?$/, 'Use a non-negative rate').optional().or(z.literal('')),
 machine_rate_per_hour: z.string().regex(/^\d+(\.\d{1,4})?$/, 'Use a non-negative rate').optional().or(z.literal('')),
 overhead_rate_per_hour: z.string().regex(/^\d+(\.\d{1,4})?$/, 'Use a non-negative rate').optional().or(z.literal('')),
 qc_required: z.boolean(),
 description: z.string().max(500).optional().or(z.literal('')),
});

const schema = z.object({
 product_id: z.string().min(1, 'Product is required'),
 notes: z.string().max(1000).optional().or(z.literal('')),
 operations: z.array(operationSchema)
  .min(1, 'Add at least one operation')
  // The backend rejects duplicates too (`distinct` + a service guard), but
  // catching it here names the offending row instead of the whole array.
  .superRefine((operations, ctx) => {
   const seen = new Map<string, number>();
   operations.forEach((op, i) => {
    const key = op.sequence.trim();
    if (key === '') return;
    if (seen.has(key)) {
     ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: [i, 'sequence'],
      message: 'Sequence must be unique',
     });
     return;
    }
    seen.set(key, i);
   });
  }),
});

type FormValues = z.infer<typeof schema>;

const blankOperation = {
 sequence: '', operation_name: '', work_center: '', machine_id: '', mold_id: '',
 setup_time_minutes: '', cycle_time_minutes: '', labor_rate_per_hour: '',
 machine_rate_per_hour: '', overhead_rate_per_hour: '', qc_required: false, description: '',
};

/** Backend list endpoints cap `per_page` at 100. */
const LOOKUP_PAGE_SIZE = 100;

// ─── Component ───────────────────────────────────────────────────

export default function RoutingEditorPage() {
 const { id } = useParams<{ id: string }>();
 const isEdit = !!id;
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('production.routings.manage');
 const [productSearch, setProductSearch] = useState('');
 const [confirmActivate, setConfirmActivate] = useState(false);

 // ── Existing routing (edit / view mode) ──────────────────
 const existing = useQuery({
 queryKey: ['production', 'routings', 'detail', id],
 queryFn: () => routingsApi.show(id!),
 enabled: isEdit,
 });

 // A superseded version is history: it is the definition past work orders
 // were built from, so it is never edited in place. Rolling back is an
 // explicit `activate` call, and changing it means duplicating it forward.
 const isSuperseded = isEdit && existing.data ? !existing.data.is_active : false;
 const readOnly = !canManage || isSuperseded;

 const {
 register, control, handleSubmit, setError, reset, watch,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { product_id: '', notes: '', operations: [{ ...blankOperation }] },
 });

 const { fields, append, remove } = useFieldArray({ control, name: 'operations' });
 const selectedProductId = watch('product_id');
 const operationScrollerRef = useRef<HTMLDivElement>(null);
 const [operationScrollEdges, setOperationScrollEdges] = useState({ left: false, right: false });

 useEffect(() => {
 const scroller = operationScrollerRef.current;
 if (!scroller) return;
 const update = () => {
 const maxScroll = scroller.scrollWidth - scroller.clientWidth;
 const next = { left: scroller.scrollLeft > 1, right: maxScroll - scroller.scrollLeft > 1 };
 setOperationScrollEdges((current) => current.left === next.left && current.right === next.right ? current : next);
 };
 update();
 scroller.addEventListener('scroll', update, { passive: true });
 const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update);
 observer?.observe(scroller);
 const table = scroller.querySelector('table');
 if (table) observer?.observe(table);
 return () => {
 scroller.removeEventListener('scroll', update);
 observer?.disconnect();
 };
 }, [fields.length, readOnly]);

 // ── Lookups ──────────────────────────────────────────────
 // Master data is paginated server-side, so the selects search rather than
 // silently showing the first page and hiding the rest.
 const products = useQuery({
 queryKey: ['crm', 'products', 'routing-lookup', productSearch],
 queryFn: ({ signal }) => productsApi.list({
 per_page: LOOKUP_PAGE_SIZE,
 is_active: 'true',
 search: productSearch || undefined,
 }, signal),
 enabled: !isEdit,
 });
 const machines = useQuery({
 queryKey: ['mrp', 'machines', 'routing-lookup'],
 queryFn: () => machinesApi.list({ per_page: LOOKUP_PAGE_SIZE }),
 enabled: !readOnly,
 });
 // Molds belong to exactly one product, and the backend rejects a mold
 // configured for a different one — so scope the list instead of offering
 // every mold in the plant.
 const molds = useQuery({
 queryKey: ['mrp', 'molds', 'routing-lookup', selectedProductId],
 queryFn: () => moldsApi.list({ per_page: LOOKUP_PAGE_SIZE, product_id: selectedProductId }),
 enabled: !readOnly && Boolean(selectedProductId),
 });

 const productsTruncated = (products.data?.meta.total ?? 0) > (products.data?.data.length ?? 0);
 const machinesTruncated = (machines.data?.meta.total ?? 0) > (machines.data?.data.length ?? 0);

 // Populate form when viewing or editing an existing routing.
 useEffect(() => {
 if (!existing.data) return;
 const r = existing.data;
 reset({
 product_id: r.product?.id ?? '',
 notes: r.notes ?? '',
 operations: r.operations.length > 0
 ? r.operations.map((op) => ({
 sequence: String(op.sequence),
 operation_name: op.operation_name,
 work_center: op.work_center ?? '',
 machine_id: op.machine?.id ?? '',
 mold_id: op.mold?.id ?? '',
 setup_time_minutes: op.setup_time_minutes ?? '',
 cycle_time_minutes: op.cycle_time_minutes ?? '',
 labor_rate_per_hour: op.labor_rate_per_hour ?? '',
 machine_rate_per_hour: op.machine_rate_per_hour ?? '',
 overhead_rate_per_hour: op.overhead_rate_per_hour ?? '',
 qc_required: op.qc_required,
 description: op.description ?? '',
 }))
 : [{ ...blankOperation }],
 });
 }, [existing.data, reset]);

 // ── Mutations ────────────────────────────────────────────
 const saveMut = useMutation({
 mutationFn: (values: FormValues) => {
 const payload = {
 product_id: values.product_id,
 notes: values.notes || null,
 operations: values.operations.map((op) => ({
 sequence: Number(op.sequence),
 operation_name: op.operation_name,
 work_center: op.work_center || null,
 machine_id: op.machine_id || null,
 mold_id: op.mold_id || null,
 setup_time_minutes: op.setup_time_minutes || null,
 cycle_time_minutes: op.cycle_time_minutes,
 labor_rate_per_hour: op.labor_rate_per_hour || null,
 machine_rate_per_hour: op.machine_rate_per_hour || null,
 overhead_rate_per_hour: op.overhead_rate_per_hour || null,
 qc_required: op.qc_required,
 description: op.description || null,
 })),
 };
 return isEdit
 ? routingsApi.update(id!, payload)
 : routingsApi.create(payload);
 },
 onSuccess: (routing) => {
 qc.invalidateQueries({ queryKey: ['production', 'routings'] });
 // An edit publishes a new version rather than rewriting the old one —
 // say so, otherwise the version jump on the next screen looks like a bug.
 toast.success(
 isEdit
 ? `Routing v${routing.version} published; the previous version is kept as history.`
 : `Routing v${routing.version} created.`,
 );
 navigate(`/production/routings/${routing.id}`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
 setError(field as never, { type: 'server', message: msgs[0] });
 });
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to save routing.');
 }
 },
 });

 const activateMut = useMutation({
 mutationFn: () => routingsApi.activate(id!),
 onSuccess: (routing) => {
 qc.invalidateQueries({ queryKey: ['production', 'routings'] });
 setConfirmActivate(false);
 toast.success(`Routing v${routing.version} is now the active process plan.`);
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 setConfirmActivate(false);
 toast.error(e.response?.data?.message ?? 'Failed to activate this version.');
 },
 });

 const duplicateMut = useMutation({
 mutationFn: () => routingsApi.duplicate(id!),
 onSuccess: (routing) => {
 qc.invalidateQueries({ queryKey: ['production', 'routings'] });
 toast.success(`Routing v${routing.version} created from this version.`);
 navigate(`/production/routings/${routing.id}`);
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Failed to duplicate routing.');
 },
 });

 // ── Loading / error for edit mode ────────────────────────
 if (isEdit && existing.isLoading) {
 return (
 <PageShell variant="grid">
 <PageHeader
 inset={false}
 title="Loading routing..."
 backTo="/production/routings"
 backLabel="Routings"
 />
 <SkeletonForm />
 </PageShell>
 );
 }

 if (isEdit && existing.isError) {
 return (
 <PageShell variant="grid">
 <PageHeader
 inset={false}
 title="Routing"
 backTo="/production/routings"
 backLabel="Routings"
 />
 <EmptyState
 icon="alert-circle"
 title="Failed to load routing"
 action={<Button variant="secondary" onClick={() => existing.refetch()}>Retry</Button>}
 />
 </PageShell>
 );
 }

 const productLabel = existing.data?.product ? ` — ${existing.data.product.part_number}` : '';
 const pageTitle = !isEdit
 ? 'New routing'
 : readOnly
 ? `Routing v${existing.data?.version ?? ''}${productLabel}`
 : `Edit routing${productLabel}`;
 const pageSubtitle = !isEdit
 ? undefined
 : isSuperseded
 ? 'Superseded version — kept as the definition past work orders were built from.'
 : readOnly
 ? 'Active process plan. You have read-only access to routings.'
 : 'Saving publishes a new version; this one is kept as history.';

 return (
 <PageShell variant="grid">
 <PageHeader
 inset={false}
 title={pageTitle}
 subtitle={pageSubtitle}
 backTo="/production/routings"
 backLabel="Routings"
 actions={
 isEdit && existing.data ? (
 <div className="flex items-center gap-2">
 <Chip variant={existing.data.is_active ? 'success' : 'neutral'}>
 {existing.data.is_active ? 'Active' : 'Superseded'}
 </Chip>
 {canManage && isSuperseded && (
 <>
 <Button
 size="sm"
 variant="secondary"
 icon={<LuCheck size={14} />}
 onClick={() => setConfirmActivate(true)}
 disabled={activateMut.isPending}
 >
 Make active
 </Button>
 <Button
 size="sm"
 variant="secondary"
 icon={<LuCopy size={14} />}
 onClick={() => duplicateMut.mutate()}
 disabled={duplicateMut.isPending}
 loading={duplicateMut.isPending}
 >
 Duplicate to edit
 </Button>
 </>
 )}
 </div>
 ) : undefined
 }
 />

 <form
 onSubmit={handleSubmit((v) => saveMut.mutate(v), onFormInvalid<FormValues>())}
 className="min-w-0 w-full"
 data-touch-target
 >
 {/* Product + notes */}
 <fieldset className="mb-8" disabled={readOnly}>
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Product</legend>
 <FormGrid columns={2}>
 <div className="space-y-2">
 {isEdit ? (
 <>
 {/* The product of a published routing is fixed — a routing for a
 different product is a different routing. Kept in the form so
 the payload still carries it. */}
 <input type="hidden" {...register('product_id')} />
 <div className="flex flex-col gap-1">
 <span className="text-xs text-muted font-medium">Finished good</span>
 <span className="font-mono text-sm text-accent">{existing.data?.product?.part_number ?? '—'}</span>
 <span className="text-xs text-muted">{existing.data?.product?.name ?? ''}</span>
 </div>
 </>
 ) : (
 <>

 <Select searchable onSearch={(value) => setProductSearch(value)} title="Part number or name…"
 label="Finished good"
 required
 {...register('product_id')}
 error={errors.product_id?.message}
 helper={products.isLoading ? 'Loading products…' : productsTruncated ? `Showing ${products.data?.data.length} of ${products.data?.meta.total} products. Search to narrow the list.` : undefined}
 >
 <option value="">Select product...</option>
 {products.data?.data?.map((p) => (
 <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
 ))}
 </Select>
 </>
 )}
 </div>
 <Textarea
 label="Notes"
 {...register('notes')}
 error={errors.notes?.message}
 maxLength={1000}
 placeholder="Optional routing notes..."
 />
 </FormGrid>
 </fieldset>

 {/* Operations table */}
 <fieldset className="mb-8 min-w-0" disabled={readOnly}>
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Operations</legend>
 {!readOnly && machines.isError && (
 <p className="mb-2 text-xs text-danger-fg">
 Could not load machines.{' '}
 <button type="button" className="underline cursor-pointer" onClick={() => machines.refetch()}>Retry</button>
 </p>
 )}
 {!readOnly && machinesTruncated && (
 <p className="mb-2 text-xs text-muted">
 Showing {machines.data?.data.length} of {machines.data?.meta.total} machines.
 </p>
 )}
 {!readOnly && molds.isError && (
 <p className="mb-2 text-xs text-danger-fg">
 Could not load molds.{' '}
 <button type="button" className="underline cursor-pointer" onClick={() => molds.refetch()}>Retry</button>
 </p>
 )}
 {/* Twelve columns do not fit a 375px viewport; scroll rather than clip. */}
 <div className="scroll-edge-frame min-w-0" data-more-left={operationScrollEdges.left} data-more-right={operationScrollEdges.right}>
 <div ref={operationScrollerRef} data-layout="table-scroller" role="region" aria-label="Routing operation table" tabIndex={0}
 className="table-scroll-container contain-layout w-full min-w-0 rounded-md border border-default overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2">
 <table className={cn(tableCls, 'min-w-max table-fixed')}>
 <thead>
 <tr className={theadTrCls}>
 <Th align="right" className="w-12">#</Th>
 <Th className="w-48">Operation</Th>
 <Th className="w-32">Work center</Th>
 <Th className="w-32">Machine</Th>
 <Th className="w-32">Mold</Th>
 <Th align="right" className="w-28">Setup (min)</Th>
 <Th align="right" className="w-28">Cycle (min)</Th>
 <Th align="right" className="w-28">Labor / hr</Th>
 <Th align="right" className="w-28">Machine / hr</Th>
 <Th align="right" className="w-28">Overhead / hr</Th>
 <Th align="center" className="w-16">QC</Th>
 {!readOnly && <Th align="right" className="w-12" />}
 </tr>
 </thead>
 {fields.map((field, i) => (
 // One tbody per operation: the work instruction spans the full
 // width, so it gets its own row rather than a thirteenth column
 // too narrow to type 500 characters into.
 <tbody key={field.id}>
 <tr className={cn(trCls, 'align-top')}>
 <Td align="right">
 <div className="flex items-center gap-1 text-muted">
 <LuGripVertical size={12} className="shrink-0" aria-hidden />
 <Input
 {...register(`operations.${i}.sequence` as const)}
 error={errors.operations?.[i]?.sequence?.message}
 className="font-mono text-right w-12"
 placeholder="10"
 />
 </div>
 </Td>
 <Td>
 <Input
 {...register(`operations.${i}.operation_name` as const)}
 error={errors.operations?.[i]?.operation_name?.message}
 placeholder="Operation name"
 />
 </Td>
 <Td>
 <Input
 {...register(`operations.${i}.work_center` as const)}
 error={errors.operations?.[i]?.work_center?.message}
 placeholder="Work center"
 />
 </Td>
 <Td>
 {readOnly ? (
 <span className="font-mono text-sm">{existing.data?.operations[i]?.machine?.machine_code ?? '—'}</span>
 ) : (
 <Select aria-label={`Machine for operation ${i + 1}`} searchable
 {...register(`operations.${i}.machine_id` as const)}
 error={errors.operations?.[i]?.machine_id?.message}
 >
 <option value="">—</option>
 {machines.data?.data?.map((m) => (
 <option key={m.id} value={m.id}>{m.machine_code} — {m.name}</option>
 ))}
 </Select>
 )}
 </Td>
 <Td>
 {readOnly ? (
 <span className="font-mono text-sm">{existing.data?.operations[i]?.mold?.mold_code ?? '—'}</span>
 ) : (
 <>
 <Select aria-label={`Mold for operation ${i + 1}`} searchable
 {...register(`operations.${i}.mold_id` as const)}
 error={errors.operations?.[i]?.mold_id?.message}
 >
 <option value="">—</option>
 {molds.data?.data?.map((m) => (
 <option key={m.id} value={m.id}>{m.mold_code} — {m.name}</option>
 ))}
 </Select>
 {!selectedProductId && (
 <p className="mt-1 text-caption text-muted">Select a product first.</p>
 )}
 {selectedProductId && molds.isSuccess && molds.data.data.length === 0 && (
 <p className="mt-1 text-caption text-muted">No usable mold for this product.</p>
 )}
 </>
 )}
 </Td>
 <Td align="right">
 <Input
 {...register(`operations.${i}.setup_time_minutes` as const)}
 error={errors.operations?.[i]?.setup_time_minutes?.message}
 placeholder="0"
 className="font-mono text-right"
 />
 </Td>
 <Td align="right">
 <Input
 {...register(`operations.${i}.cycle_time_minutes` as const)}
 error={errors.operations?.[i]?.cycle_time_minutes?.message}
 placeholder="0"
 className="font-mono text-right"
 />
 </Td>
 <Td align="right">
 <Input
 {...register(`operations.${i}.labor_rate_per_hour` as const)}
 error={errors.operations?.[i]?.labor_rate_per_hour?.message}
 placeholder="0"
 className="font-mono text-right"
 />
 </Td>
 <Td align="right">
 <Input
 {...register(`operations.${i}.machine_rate_per_hour` as const)}
 error={errors.operations?.[i]?.machine_rate_per_hour?.message}
 placeholder="0"
 className="font-mono text-right"
 />
 </Td>
 <Td align="right">
 <Input
 {...register(`operations.${i}.overhead_rate_per_hour` as const)}
 error={errors.operations?.[i]?.overhead_rate_per_hour?.message}
 placeholder="0"
 className="font-mono text-right"
 />
 </Td>
 <Td align="center">
 <div className="flex items-center justify-center min-h-8">
 <Checkbox {...register(`operations.${i}.qc_required` as const)} />
 </div>
 </Td>
 {!readOnly && (
 <Td align="right" mono>
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuTrash2 size={14} />}
 aria-label="Remove operation"
 onClick={() => remove(i)}
 disabled={fields.length === 1}
 className="text-muted hover:text-danger-fg"
 />
 </Td>
 )}
 </tr>
 <tr className="border-b border-subtle">
 <Td />
 <Td colSpan={readOnly ? 10 : 11}>
 <Textarea
 label={`Work instruction — operation ${i + 1}`}
 rows={2}
 maxLength={500}
 {...register(`operations.${i}.description` as const)}
 error={errors.operations?.[i]?.description?.message}
 placeholder="Optional operator instruction, gauge, or setting…"
 />
 </Td>
 </tr>
 </tbody>
 ))}
 </table>
 </div>
 </div>

 {!readOnly && (
 <div className="mt-3 flex items-center gap-3">
 <Button
 type="button"
 variant="secondary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => {
 const nextSeq = fields.length > 0
 ? String((Math.max(...fields.map((_, i) => {
 const el = document.querySelector<HTMLInputElement>(`[name="operations.${i}.sequence"]`);
 return el ? Number(el.value) || 0 : 0;
 })) + 10))
 : '10';
 append({ ...blankOperation, sequence: nextSeq });
 }}
 >
 Add operation
 </Button>
 </div>
 )}

 {errors.operations?.message && (
 <p className="mt-2 text-xs text-danger-fg">{errors.operations.message as string}</p>
 )}
 </fieldset>

 {/* Submit / cancel */}
 <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
 <Button type="button" variant="secondary" onClick={() => navigate('/production/routings')}>
 {readOnly ? 'Back to routings' : 'Cancel'}
 </Button>
 {!readOnly && (
 <Button
 type="submit"
 variant="primary"
 disabled={isSubmitting || saveMut.isPending}
 loading={saveMut.isPending}
 >
 {saveMut.isPending ? 'Saving...' : isEdit ? 'Publish new version' : 'Create routing'}
 </Button>
 )}
 </div>
 </form>

 <ConfirmDialog
 isOpen={confirmActivate}
 onClose={() => setConfirmActivate(false)}
 onConfirm={() => activateMut.mutate()}
 pending={activateMut.isPending}
 variant="warning"
 title={`Make v${existing.data?.version ?? ''} the active routing?`}
 description="New work orders will be generated from this version and the product's BOM will be re-costed from its rates. The version currently active becomes history."
 confirmLabel="Make active"
 />
 </PageShell>
 );
}
