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
import { useEffect, useMemo, useRef, useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { LuArchiveRestore, LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { onFormInvalid, reportMutationError } from '@/lib/formErrors';
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
import type { InspectionSpecRevision, UpsertInspectionSpecData } from '@/types/quality';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { usePermission } from '@/hooks/usePermission';
import { formatDateIso } from '@/lib/formatDate';
import { inspectionMethods } from '@/lib/inspectionMethods';
import { uomsApi } from '@/api/inventory/uoms';

const checkOptions = [
  { name: 'Product identity', method: '', criterion: 'Product matches the ordered part and its approved identification.' },
  { name: 'Appearance', method: 'visual', criterion: 'No cracks, damage, contamination, flash or short shots.' },
  { name: 'Size', method: 'dimensional', criterion: 'Dimensions are within the limits on the approved product drawing.' },
  { name: 'Fit / function', method: 'functional', criterion: 'Fits the approved inspection gauge or mating part and performs the required function.' },
  { name: 'Packaging', method: 'visual', criterion: 'Packaging protects the product, and labels match the product and batch.' },
];
const measurementUnits = ['mm', 'cm', 'm', 'µm', 'g', 'kg', '°C', 'N', '%'];

const signedDecimalPattern = /^-?\d{1,8}(?:\.\d{1,4})?$/;
const itemSchema = z
  .object({
    parameter_name: z.string().trim().min(1, 'Choose what to check or enter a custom name.').max(150),
    parameter_type: z.string().nullable().optional(),
    acceptance_criterion: z.string().trim().max(500).optional(),
    unit_of_measure: z.string().max(20).optional().or(z.literal('')),
    nominal_value: z
      .string()
      .refine(
        (value) => value === '' || signedDecimalPattern.test(value),
        'Use a signed decimal with up to 4 places',
      )
      .optional()
      .or(z.literal('')),
    tolerance_min: z
      .string()
      .refine(
        (value) => value === '' || signedDecimalPattern.test(value),
        'Use a signed decimal with up to 4 places',
      )
      .optional()
      .or(z.literal('')),
    tolerance_max: z
      .string()
      .refine(
        (value) => value === '' || signedDecimalPattern.test(value),
        'Use a signed decimal with up to 4 places',
      )
      .optional()
      .or(z.literal('')),
    is_critical: z.boolean().optional(),
    notes: z.string().max(500).optional().or(z.literal('')),
  })
  .superRefine((row, ctx) => {
    const nominal = row.nominal_value || null;
    const minimum = row.tolerance_min || null;
    const maximum = row.tolerance_max || null;
    const hasNumeric = Boolean(nominal || minimum || maximum);
    if (!minimum && !maximum && !row.acceptance_criterion?.trim() && !row.notes?.trim()) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['acceptance_criterion'], message: 'Enter the PASS requirement or approved reference.' });
    }
    if (row.parameter_type === 'visual' && hasNumeric) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['parameter_type'],
        message: 'Visual checks cannot have numeric limits.',
      });
    }
    if (row.parameter_type === 'dimensional') {
      if (!row.unit_of_measure?.trim())
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['unit_of_measure'],
          message: 'Choose a measurement unit.',
        });
      if (!nominal)
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['nominal_value'],
          message: 'Enter the measurement target.',
        });
      if (!minimum)
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['tolerance_min'],
          message: 'Enter the lowest allowed value.',
        });
      if (!maximum)
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['tolerance_max'],
          message: 'Enter the highest allowed value.',
        });
    }
    if (row.parameter_type === 'functional' && nominal && !minimum && !maximum) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['nominal_value'],
        message: 'A target needs at least one allowed limit.',
      });
    }
    if (minimum && maximum && Number(minimum) > Number(maximum)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['tolerance_min'],
        message: 'Lowest allowed must not exceed highest allowed.',
      });
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['tolerance_max'],
        message: 'Highest allowed must not be below lowest allowed.',
      });
    }
    if (nominal && minimum && Number(nominal) < Number(minimum)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['nominal_value'],
        message: 'Target must be within the allowed limits.',
      });
    }
    if (nominal && maximum && Number(nominal) > Number(maximum)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['nominal_value'],
        message: 'Target must be within the allowed limits.',
      });
    }
  });
const schema = z.object({
  product_id: z.string().min(1, 'Product is required'),
  notes: z.string().max(2000).optional().or(z.literal('')),
  items: z.array(itemSchema).min(1, 'Add at least one check').max(100),
}).superRefine((values, context) => {
  const seen = new Set<string>();
  values.items.forEach((row, index) => {
    const name = row.parameter_name.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
    if (seen.has(name)) context.addIssue({ code: z.ZodIssueCode.custom, path: ['items', index, 'parameter_name'], message: 'Check names must be unique.' });
    seen.add(name);
  });
});

type FormValues = z.infer<typeof schema>;

export default function InspectionSpecEditorPage() {
  const { productId: productIdParam, specId: specIdParam } = useParams<{
    productId: string;
    specId: string;
  }>();
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
    : isNewMode
      ? pickedProductId
      : (productIdParam ?? '');

  const products = useQuery({
    queryKey: ['crm', 'products', 'lookup'],
    queryFn: () => productsApi.options({ per_page: 100, is_active: 'true' }),
  });
  const parameterOptions = useQuery({
    queryKey: ['quality', 'inspection-specs', 'options'],
    queryFn: () => inspectionSpecsApi.options(),
  });
  const captureEnabled = parameterOptions.data?.checklist_capture_enabled === true;
  const units = useQuery({
    queryKey: ['inventory', 'uoms'],
    queryFn: uomsApi.list,
    enabled: can('quality.specs.view') || can('quality.specs.manage'),
    staleTime: 300_000,
  });
  const [customChecks, setCustomChecks] = useState<Set<string>>(new Set());
  const [numericChecks, setNumericChecks] = useState<Set<string>>(new Set());
  const [editingChecks, setEditingChecks] = useState<Set<string>>(new Set());
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
  const [selectedRevisionId, setSelectedRevisionId] = useState<string | null>(
    searchParams.get('revision'),
  );
  const selectedRevision: InspectionSpecRevision | undefined =
    revisions.data?.find((revision) => revision.id === selectedRevisionId) ??
    revisions.data?.find((revision) => revision.is_current) ??
    revisions.data?.[0];
  useEffect(() => {
    if (!revisions.data?.length) return;
    if (
      !selectedRevisionId ||
      !revisions.data.some((revision) => revision.id === selectedRevisionId)
    ) {
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
  const cpkThresholds = spcOptions?.capability_thresholds;

  const {
    register,
    control,
    handleSubmit,
    getValues,
    watch,
    reset,
    setValue,
    setError,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<z.input<typeof schema>, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      product_id: productId,
      notes: '',
      items: [
        {
          parameter_name: '',
          acceptance_criterion: '',
          parameter_type: '',
          is_critical: false,

        },
      ],
    },
  });
  useUnsavedChangesGuard(isDirty && !readOnly);

  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const selectedNames = new Set(watch('items').map(row => row.parameter_name.trim().replace(/\s+/g, ' ').toLocaleLowerCase()));
  const hasRemainingChecks = checkOptions.some(option => !selectedNames.has(option.name.toLocaleLowerCase()));
  const initializedProduct = useRef('');

  useEffect(() => {
    if (!errors.items) return;
    setEditingChecks(previous => {
      const next = new Set(previous);
      fields.forEach((field, index) => {
        if (errors.items?.[index]) next.add(field.id);
      });
      return next;
    });
  }, [errors.items, fields]);

  // Pre-fill once the existing spec query resolves (or once user picks a product in new mode).
  useEffect(() => {
    if (!productId) return;
    if (isSpecDetailMode && specDetail.data === undefined) return;
    if (!isSpecDetailMode && existing.data === undefined) return;
    if (!loadedSpec && products.isPending) return;
    if (initializedProduct.current === productId) return;
    initializedProduct.current = productId;
    if (loadedSpec) {
      reset({
        product_id: productId,
        notes: loadedSpec.notes ?? '',
        items: (loadedSpec.items ?? []).map((it) => ({
          parameter_name: it.parameter_name,
          acceptance_criterion: it.acceptance_criterion ?? '',
          parameter_type: it.parameter_type,
          unit_of_measure: it.unit_of_measure ?? '',
          nominal_value: it.nominal_value == null ? '' : String(it.nominal_value),
          tolerance_min: it.tolerance_min == null ? '' : String(it.tolerance_min),
          tolerance_max: it.tolerance_max == null ? '' : String(it.tolerance_max),
          notes: it.notes ?? '',
          is_critical: it.is_critical,

        })),
      });
    } else {
      const product = products.data?.data.find(candidate => candidate.id === productId);
      reset({
        product_id: productId,
        notes: '',
        items: checkOptions.map(option => ({
          parameter_name: option.name,
          parameter_type: option.method,
          acceptance_criterion: option.name === 'Product identity' && product
            ? `Product matches ${product.part_number} — ${product.name} and its approved identification.`
            : option.criterion,
          is_critical: false,
          unit_of_measure: '',
          nominal_value: '',
          tolerance_min: '',
          tolerance_max: '',
          notes: '',
        })),
      });
    }
  }, [
    productId,
    loadedSpec,
    products.data,
    products.isPending,
    isSpecDetailMode,
    specDetail.data,
    existing.data,
    reset,
  ]);

  const upsert = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: UpsertInspectionSpecData = {
        product_id: values.product_id,
        notes: values.notes || undefined,
        capture_method: 'checklist',
        items: values.items.map((row, i) => ({
          parameter_name: row.parameter_name,
          acceptance_criterion: row.acceptance_criterion || undefined,
          unit_of_measure: row.unit_of_measure || undefined,
          nominal_value: row.nominal_value || undefined,
          tolerance_min: row.tolerance_min || undefined,
          tolerance_max: row.tolerance_max || undefined,
          notes: row.notes || undefined,
          parameter_type: (row.parameter_type || null) as UpsertInspectionSpecData['items'][number]['parameter_type'],
          is_critical: row.is_critical ?? false,
          sort_order: i,
        })),
      };
      return inspectionSpecsApi.upsert(payload);
    },
    onSuccess: (spec) => {
      reset(getValues());
      qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs'] });
      qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs', 'for-product', productId] });
      qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs', 'spc'] });
      toast.success(`Spec v${spec.version} saved.`);
      navigate(
        isSpecDetailMode
          ? `/quality/inspection-specs/spec/${spec.id}`
          : `/quality/inspection-specs/${productId}`,
      );
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
    onError: (error) => reportMutationError(error, 'Failed to restore inspection spec'),
  });

  const productLabel = useMemo(() => {
    const p =
      loadedSpec?.product ??
      products.data?.data?.find(
        (pp: { id: string; part_number: string; name: string }) => pp.id === productId,
      );
    return p ? `${p.part_number} — ${p.name}` : '';
  }, [loadedSpec?.product, products.data, productId]);

  // ── New-spec mode without picked product yet
  if (isNewMode && !can('quality.specs.manage')) {
    return (
      <div>
        <PageHeader
          title="New inspection spec"
          backTo="/quality/inspection-specs"
          backLabel="Inspection specs"
        />
        <div className="min-w-0 w-full px-5 py-4">
          <EmptyState
            icon="lock"
            title="Authoring permission required"
            description="You can view inspection specs, but only quality managers can create or revise them."
          />
        </div>
      </div>
    );
  }

  if (isNewMode && !pickedProductId) {
    return (
      <div>
        <PageHeader
          title="New inspection spec"
          backTo="/quality/inspection-specs"
          backLabel="Inspection specs"
        />
        <div className="min-w-0 w-full px-5 py-4 space-y-4">
          <h2 className="text-base font-semibold">Choose a product</h2>
          <p className="text-sm text-muted">Start with suggested checks, then adjust them to the approved product requirements.</p>
          <Select searchable
            containerClassName="max-w-xl"
            label="Product"
            required
            value={pickedProductId}
            onChange={(e) => setPickedProductId(e.target.value)}
          >
            <option value="">Select product…</option>
            {products.data?.data?.map((p: { id: string; part_number: string; name: string }) => (
              <option key={p.id} value={p.id}>
                {p.part_number} — {p.name}
              </option>
            ))}
          </Select>
          <p className="text-xs text-muted">
            Pick a product to author or replace its inspection spec. Each product carries one active
            spec; saving creates a new version.
          </p>
        </div>
      </div>
    );
  }

  // ── Loading existing spec
  if (productId && (existing.isLoading || specDetail.isLoading)) {
    return (
      <div>
        <PageHeader
          title="Inspection spec"
          backTo="/quality/inspection-specs"
          backLabel="Inspection specs"
        />
        <SkeletonForm />
      </div>
    );
  }

  // ── Error loading existing spec
  if (existing.isError || specDetail.isError) {
    return (
      <div>
        <PageHeader
          title="Inspection spec"
          backTo="/quality/inspection-specs"
          backLabel="Inspection specs"
        />
        <EmptyState
          icon="alert-circle"
          title="Failed to load spec"
          action={
            <Button
              variant="secondary"
              onClick={() => (isSpecDetailMode ? specDetail.refetch() : existing.refetch())}
            >
              Retry
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={
          <div className="flex flex-wrap items-center gap-3">
            <span>{productLabel || 'Inspection spec'}</span>
            {loadedSpec && (
              <Chip variant={loadedSpec.is_active ? 'success' : 'neutral'}>
                {loadedSpec.is_active ? `v${loadedSpec.version}` : 'Archived'}
              </Chip>
            )}
            {!loadedSpec && <Chip variant="info">New</Chip>}
          </div>
        }
        backTo="/quality/inspection-specs"
        backLabel="Inspection specs"
      />
      <form
        noValidate
        onSubmit={
          readOnly
            ? (e) => e.preventDefault()
            : handleSubmit((v) => upsert.mutate(v), onFormInvalid<FormValues>())
        }
        className="min-w-0 w-full px-5 py-4"
      >
        <input type="hidden" {...register('product_id')} value={productId} />

        <fieldset className="mb-8 min-w-0">
          <legend className="mb-2 text-base font-semibold">Inspection checks · {fields.length}</legend>
          <p className="mb-4 text-sm text-muted">Review each PASS requirement. Use Edit to change a check; add measurement limits when needed.</p>
          {!loadedSpec && <p className="mb-4 text-sm text-warning-fg">Suggested checks need review against approved requirements. Remove checks that do not apply.</p>}
          {!captureEnabled && !readOnly && <p role="alert" className="mb-3 text-warning-fg">PASS/FAIL publication is paused until the compatible inspection workflow is enabled.</p>}
          {loadedSpec?.capture_method !== 'checklist' && loadedSpec && <p className="mb-3 text-warning-fg">Existing limits and historical measurements stay preserved. Saving publishes a new specification revision for PASS/FAIL inspection.</p>}
          {errors.items?.root?.message && <p role="alert" className="mb-3 text-sm text-danger-fg">{errors.items.root.message}</p>}
          <div className="divide-y divide-default border-y border-default">
            {fields.map((field, i) => {
              const row = watch(`items.${i}`);
              const custom = customChecks.has(field.id) || Boolean(row?.parameter_name && !checkOptions.some(option => option.name === row.parameter_name));
              const hasLimits = Boolean(row?.nominal_value || row?.tolerance_min || row?.tolerance_max);
              const showLimits = row?.parameter_type === 'dimensional' || hasLimits || numericChecks.has(field.id);
              const editing = editingChecks.has(field.id) || !row?.parameter_name;
              const name = row?.parameter_name || `New check ${i + 1}`;
              const unitCodes = [...new Set([...measurementUnits, ...(units.data ?? []).map(unit => unit.code), ...(row?.unit_of_measure ? [row.unit_of_measure] : [])])];
              return <section key={field.id} aria-label={`Check ${i + 1}: ${name}`} className="min-w-0 space-y-3 py-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h3 className="text-sm font-semibold break-words">{name}</h3>
                  <p className="mt-1 text-xs text-muted">{inspectionMethods.find(method => method.value === (row?.parameter_type ?? ''))?.label ?? row?.parameter_type}</p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center justify-end gap-1">
                  {row?.is_critical && <Chip variant="warning">Critical</Chip>}
                  <Button type="button" size="sm" variant="secondary" aria-label={`${editing ? 'Done editing' : readOnly ? 'View' : 'Edit'} ${name}`} aria-expanded={editing} aria-controls={`check-editor-${field.id}`}
                    onClick={() => setEditingChecks(previous => { const next = new Set(previous); if (next.has(field.id)) next.delete(field.id); else next.add(field.id); return next; })}>
                    {editing ? 'Done' : readOnly ? 'View' : 'Edit'}
                  </Button>
                  {!readOnly && <Button type="button" size="sm" variant="ghost" iconOnly className="text-danger-fg" aria-label={`Remove check ${i + 1}`} title={fields.length === 1 ? 'Keep at least one check' : `Remove ${name}`} disabled={fields.length === 1} onClick={() => remove(i)}><LuTrash2 size={16} /></Button>}
                </div>
              </div>
              {!editing && <div className="text-sm text-secondary">
                <p className="mb-1 text-xs font-medium text-muted">PASS requirement</p>
                <p className="max-w-prose whitespace-pre-wrap break-words">{row?.acceptance_criterion || row?.notes || (hasLimits ? 'Within the measurement limits below.' : 'Add the PASS requirement using Edit.')}</p>
              </div>}
              <div id={`check-editor-${field.id}`} hidden={!editing} className="space-y-3">
              <div className="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_minmax(0,1fr)]">
                <Select label={`What to check ${i + 1}`} required disabled={readOnly} value={custom ? '__custom__' : row?.parameter_name ?? ''}
                  error={custom ? undefined : errors.items?.[i]?.parameter_name?.message}
                  onChange={event => {
                    const choice = event.target.value;
                    const suggestMethod = !row?.parameter_name && !hasLimits;
                    const suggestCriterion = !row?.acceptance_criterion?.trim() && !row?.notes?.trim();
                    const option = checkOptions.find(candidate => candidate.name === choice);
                    if (!row?.parameter_name) setEditingChecks(previous => new Set(previous).add(field.id));
                    setCustomChecks(old => { const next = new Set(old); if (choice === '__custom__') next.add(field.id); else next.delete(field.id); return next; });
                    if (choice === '__custom__') return;
                    setValue(`items.${i}.parameter_name`, choice, { shouldDirty: true });
                    // Never replace an established method or limits when changing a check name.
                    if (suggestMethod) setValue(`items.${i}.parameter_type`, option?.method ?? '', { shouldDirty: true });
                    if (suggestCriterion && option) setValue(`items.${i}.acceptance_criterion`, option.criterion, { shouldDirty: true });
                  }}>
                  <option value="">Choose a check…</option>
                  {checkOptions.map(option => <option key={option.name} value={option.name} disabled={option.name !== row?.parameter_name && selectedNames.has(option.name.toLocaleLowerCase())}>{option.name}</option>)}
                  <option value="__custom__">Custom check</option>
                </Select>
                <Select label={`How to check ${i + 1}`} {...register(`items.${i}.parameter_type`)} value={row?.parameter_type ?? ''} disabled={readOnly} error={errors.items?.[i]?.parameter_type?.message}>
                  {inspectionMethods.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                </Select>
                <Select label={`Importance ${i + 1}`} value={row?.is_critical ? 'critical' : 'normal'} disabled={readOnly}
                  onChange={event => setValue(`items.${i}.is_critical`, event.target.value === 'critical', { shouldDirty: true })}>
                  <option value="normal">Normal</option>
                  <option value="critical">Critical</option>
                </Select>
              </div>
              {custom ? <Input label={`Custom check name ${i + 1}`} {...register(`items.${i}.parameter_name`)} maxLength={150} required readOnly={readOnly} error={errors.items?.[i]?.parameter_name?.message} placeholder="For example: Outer diameter" />
                : <input type="hidden" {...register(`items.${i}.parameter_name`)} />}
              <Textarea label={`PASS requirement ${i + 1}`} {...register(`items.${i}.acceptance_criterion`)} maxLength={500} rows={2} showCounter={false} readOnly={readOnly} error={errors.items?.[i]?.acceptance_criterion?.message} placeholder="For example: No cracks or damage. Use the approved product requirements." />
              <details open={Boolean(row?.notes || errors.items?.[i]?.notes)} className="text-sm">
                <summary className="cursor-pointer text-muted focus-visible:outline-accent">Additional instructions (optional)</summary>
                <Textarea containerClassName="mt-2" label={`Additional instructions ${i + 1}`} {...register(`items.${i}.notes`)} maxLength={500} rows={2} showCounter={false} readOnly={readOnly} error={errors.items?.[i]?.notes?.message} placeholder="Inspection method or supporting instructions" />
              </details>
              </div>
              {row?.is_critical && <p className="text-xs text-muted">Critical: any failure prevents acceptance.</p>}
              {row?.parameter_type === 'functional' && !showLimits && !readOnly && <Button type="button" variant="ghost" size="sm" onClick={() => setNumericChecks(old => new Set(old).add(field.id))}>Add measurement limits</Button>}
              {showLimits && <div className="space-y-2">
                <h4 className="text-sm font-medium">Measurement limits <span className="font-normal text-muted">({row?.parameter_type === 'dimensional' ? 'required' : 'optional'})</span></h4>
                <p className="text-xs text-muted">{row?.parameter_type === 'dimensional' ? 'Enter the unit, target and both limits from the approved drawing. Actual size is required during inspection.' : 'Use approved values when numeric limits apply; gauge checks can use a written reference.'}</p>
                <div className="grid min-w-0 grid-cols-2 gap-3 md:grid-cols-4">
                  <Select label={`Unit ${i + 1}`} required={row?.parameter_type === 'dimensional'} {...register(`items.${i}.unit_of_measure`)} value={row?.unit_of_measure ?? ''} disabled={readOnly} error={errors.items?.[i]?.unit_of_measure?.message}>
                    <option value="">Choose unit…</option>
                    {unitCodes.map(code => <option key={code} value={code}>{code}{units.data?.find(unit => unit.code === code)?.name ? ` — ${units.data.find(unit => unit.code === code)!.name}` : ''}</option>)}
                  </Select>
                  <Input label={`Target ${i + 1}`} required={row?.parameter_type === 'dimensional'} inputMode="decimal" {...register(`items.${i}.nominal_value`)} readOnly={readOnly} error={errors.items?.[i]?.nominal_value?.message} placeholder="Expected value" />
                  <Input label={`Lowest allowed ${i + 1}`} required={row?.parameter_type === 'dimensional'} inputMode="decimal" {...register(`items.${i}.tolerance_min`)} readOnly={readOnly} error={errors.items?.[i]?.tolerance_min?.message} placeholder="Lower limit" />
                  <Input label={`Highest allowed ${i + 1}`} required={row?.parameter_type === 'dimensional'} inputMode="decimal" {...register(`items.${i}.tolerance_max`)} readOnly={readOnly} error={errors.items?.[i]?.tolerance_max?.message} placeholder="Upper limit" />
                </div>
                {units.isError && <div role="alert" className="text-sm text-warning-fg">Unit catalog could not be loaded. Common measurement units and the saved unit remain available. <Button type="button" size="sm" variant="ghost" onClick={() => units.refetch()}>Retry units</Button></div>}
              </div>}
            </section>})}
          </div>
          {!readOnly && hasRemainingChecks && fields.length < 100 && <Button type="button" className="mt-3" variant="secondary" onClick={() => append({ parameter_name: '', acceptance_criterion: '', parameter_type: null, is_critical: false })}><LuPlus size={14} /> Add check</Button>}
        </fieldset>

        <details open={Boolean(watch('notes') || errors.notes)} className="mb-8 text-sm">
          <summary className="cursor-pointer font-medium focus-visible:outline-accent">Revision notes (optional)</summary>
          <Textarea label="Revision notes" containerClassName="mt-3" rows={2} maxLength={2000} {...register('notes')} readOnly={readOnly} error={errors.notes?.message} placeholder="Reason for creating or updating these requirements." />
        </details>

        {isSpecDetailMode && loadedSpec && (
          <fieldset className="mb-8 min-w-0">
            <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
              Revision history
            </legend>
            <p className="mb-3 text-xs text-muted">
              The authoring form above shows the current revision. Select a prior revision below to
              reconstruct the exact definition used by historical inspection evidence.
            </p>
            {revisions.isLoading && (
              <div
                className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted"
                role="status"
              >
                Loading revision history…
              </div>
            )}
            {revisions.isError && (
              <div
                className="rounded-md border border-danger-border bg-danger-bg px-3 py-3 text-sm text-danger-fg"
                role="alert"
              >
                <div>Revision history could not be loaded.</div>
                <Button
                  className="mt-2"
                  variant="secondary"
                  size="sm"
                  onClick={() => void revisions.refetch()}
                >
                  Retry history
                </Button>
              </div>
            )}
            {!revisions.isLoading &&
              !revisions.isError &&
              revisions.data &&
              revisions.data.length > 0 && (
                <>
                  <Select searchable
                    label="Revision to inspect"
                    value={selectedRevision?.id ?? ''}
                    onChange={(event) => setSelectedRevisionId(event.target.value)}
                  >
                    {revisions.data.map((revision) => (
                      <option key={revision.id} value={revision.id}>
                        v{revision.version}
                        {revision.is_current ? ' — current' : ''}
                      </option>
                    ))}
                  </Select>
                  {selectedRevision && (
                    <section
                      className="mt-3 min-w-0 space-y-3"
                      aria-label={`Inspection spec revision ${selectedRevision.version}`}
                    >
                      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                        <span>Created {formatDateIso(selectedRevision.created_at)}</span>
                        <span>By {selectedRevision.creator?.name ?? 'Unknown actor'}</span>
                        {selectedRevision.is_current && (
                          <Chip variant="info">Current revision</Chip>
                        )}
                      </div>
                      {selectedRevision.notes && (
                        <p className="text-sm text-muted">{selectedRevision.notes}</p>
                      )}
                      {selectedRevision.capture_method === 'checklist' && !selectedRevision.items.some(item => item.nominal_value != null || item.tolerance_min != null || item.tolerance_max != null) ? <ul className="divide-y divide-default rounded-md border border-default">
                        {selectedRevision.items.map(item => <li key={item.id} className="p-3">
                          <div className="flex flex-wrap gap-2 font-medium">{item.parameter_name}{item.is_critical && <Chip variant="danger">Critical</Chip>}</div>
                          <p className="mt-1 whitespace-pre-line break-words text-sm text-muted">{item.acceptance_criterion}</p>
                        </li>)}
                      </ul> : <div className="min-w-0 max-w-full overflow-x-auto rounded-md border border-default">
                        <table className={tableCls + ' min-w-[760px]'}>
                          <thead>
                            <tr className={theadTrCls}>
                              <Th>Parameter</Th>
                              <Th>Type</Th>
                              <Th>UOM</Th>
                              <Th align="right">Nominal</Th>
                              <Th align="right">Min</Th>
                              <Th align="right">Max</Th>
                              <Th>Criterion / notes</Th>
                            </tr>
                          </thead>
                          <tbody>
                            {selectedRevision.items.map((item) => (
                              <tr key={item.id} className={trCls}>
                                <Td>{item.parameter_name}</Td>
                                <Td>{item.parameter_type_label ?? item.parameter_type}</Td>
                                <Td className="font-mono">{item.unit_of_measure ?? '—'}</Td>
                                <Td align="right" mono>
                                  {item.nominal_value ?? '—'}
                                </Td>
                                <Td align="right" mono>
                                  {item.tolerance_min ?? '—'}
                                </Td>
                                <Td align="right" mono>
                                  {item.tolerance_max ?? '—'}
                                </Td>
                                <Td>{item.acceptance_criterion ?? item.notes ?? '—'}</Td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>}
                    </section>
                  )}
                </>
              )}
          </fieldset>
        )}

        {specId && loadedSpec?.capture_method !== 'checklist' && (
          <div className="mb-8">
            <h3 className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
              Process Capability (SPC)
            </h3>
            {spcData.isLoading && (
              <div
                className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted"
                role="status"
              >
                Loading current-revision SPC data…
              </div>
            )}
            {spcData.isError && (
              <div
                className="rounded-md border border-danger-border bg-danger-bg px-3 py-3 text-sm text-danger-fg"
                role="alert"
              >
                <div>SPC data could not be loaded.</div>
                <Button
                  className="mt-2"
                  variant="secondary"
                  size="sm"
                  onClick={() => void spcData.refetch()}
                >
                  Retry SPC
                </Button>
              </div>
            )}
            {!spcData.isLoading &&
              !spcData.isError &&
              spcData.data &&
              cpkThresholds &&
              Object.keys(spcData.data.data).length === 0 && (
                <div
                  className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted"
                  role="status"
                >
                  No completed inspection readings from the current revision meet the minimum sample
                  count and measurable-variation requirement for SPC yet.
                </div>
              )}
            {!spcData.isLoading &&
              !spcData.isError &&
              spcData.data &&
              cpkThresholds &&
              Object.keys(spcData.data.data).length > 0 && (
                <div className="min-w-0 max-w-full overflow-x-auto rounded-md border border-default">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Parameter</Th>
                        <Th align="right" className="font-mono">
                          Cp
                        </Th>
                        <Th align="right" className="font-mono">
                          Cpk
                        </Th>
                        <Th align="right" className="font-mono">
                          Mean
                        </Th>
                        <Th align="right" className="font-mono">
                          σ
                        </Th>
                        <Th align="right">n</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {Object.entries(spcData.data.data).map(([id, s]) => {
                        const item = s as SpcResult;
                        const cpColor =
                          item.cp >= cpkThresholds.ongoing
                            ? 'text-success-fg'
                            : item.cp >= cpkThresholds.action
                              ? 'text-warning-fg'
                              : 'text-danger-fg';
                        const cpkColor =
                          item.cpk >= cpkThresholds.ongoing
                            ? 'text-success-fg'
                            : item.cpk >= cpkThresholds.action
                              ? 'text-warning-fg'
                              : 'text-danger-fg';
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
            {spcData.data?.meta?.revision_version && (
              <p className="mt-2 text-2xs text-muted">
                Population: current revision v{spcData.data.meta.revision_version} only.
              </p>
            )}
            {spcData.data && !cpkThresholds && (
              <div
                className="rounded-md border border-subtle bg-subtle px-3 py-3 text-sm text-muted"
                role="status"
              >
                Loading SPC thresholds…
              </div>
            )}
            <p className="mt-2 text-2xs text-muted">
              {cpkThresholds ? (
                <>
                  Cp / Cpk ≥ {cpkThresholds.ongoing.toFixed(2)} = capable ·{' '}
                  {cpkThresholds.action.toFixed(1)}–{cpkThresholds.ongoing.toFixed(2)} = marginal ·
                  &lt;{cpkThresholds.action.toFixed(1)} = not capable · Minimum{' '}
                  {cpkThresholds.minimum_samples} measurements required per parameter
                </>
              ) : null}
            </p>
          </div>
        )}

        <div className="sticky bottom-0 z-10 -mx-5 -mb-4 flex flex-wrap items-center justify-end gap-2 border-t border-default bg-canvas px-5 py-3">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/quality/inspection-specs')}
          >
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
              disabled={isSubmitting || upsert.isPending || !captureEnabled || parameterOptions.isError}
              loading={upsert.isPending}
            >
              {upsert.isPending ? 'Saving…' : loadedSpec ? 'Save new version' : 'Create spec'}
            </Button>
          ) : (
            <p className="text-xs text-muted" role="status">
              {isArchived
                ? 'Archived specs are read-only until restored.'
                : 'View-only access: authoring controls are disabled.'}
            </p>
          )}
        </div>
      </form>
    </div>
  );
}
