import { useEffect, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { itemsApi, itemCategoriesApi } from '@/api/inventory/items';
import { uomsApi } from '@/api/inventory/uoms';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import { formatPeso } from '@/lib/formatNumber';
import type { CreateItemData, ReorderMethod } from '@/types/inventory';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
import { usePermission } from '@/hooks/usePermission';

const quantity = z
  .string()
  .regex(/^\d+(\.\d{1,3})?$/, 'Use a non-negative quantity with up to 3 decimals.');
const schema = z.object({
  code: z
    .string()
    .trim()
    .toUpperCase()
    .min(2, 'Code needs at least 2 characters.')
    .max(30)
    .regex(/^[A-Z0-9-]+$/, 'Use letters, digits and hyphens.'),
  name: z.string().trim().min(1, 'Name is required.').max(200),
  description: z.string().max(1000).optional(),
  category_id: z.string().min(1, 'Select a category.'),
  unit_of_measure: z.string().min(1, 'Select a base unit.').max(20),
  standard_cost: z
    .string()
    .regex(/^\d+(\.\d{1,4})?$/, 'Use a non-negative cost with up to 4 decimals.'),
  reorder_method: z.string().min(1, 'Select a reorder method.'),
  reorder_point: quantity,
  safety_stock: quantity,
  minimum_order_quantity: quantity
    .refine((value) => Number(value) > 0, 'Minimum order quantity must be greater than zero.')
    .optional()
    .or(z.literal('')),
  lead_time_days: z.coerce
    .number()
    .int('Use whole days.')
    .min(0)
    .max(365, 'Lead time cannot exceed 365 days.'),
  is_critical: z.boolean().default(false),
  is_active: z.boolean().default(true),
});
type FormValues = z.infer<typeof schema>;

export default function ItemFormPage({ mode }: { mode: 'create' | 'edit' }) {
  const { can } = usePermission();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { id = '' } = useParams<{ id: string }>();
  const existingQuery = useQuery({
    queryKey: ['inventory', 'items', id],
    queryFn: () => itemsApi.show(id),
    enabled: mode === 'edit' && !!id,
  });
  const categories = useQuery({
    queryKey: ['inventory', 'categories'],
    queryFn: () => itemCategoriesApi.list(),
  });
  const options = useQuery({
    queryKey: ['inventory', 'items', 'options'],
    queryFn: itemsApi.options,
  });
  const uoms = useQuery({
    queryKey: ['inventory', 'uoms'],
    queryFn: uomsApi.list,
    staleTime: 300_000,
  });
  const form = useForm<z.input<typeof schema>, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: '',
      name: '',
      description: '',
      category_id: '',
      unit_of_measure: '',
      standard_cost: '',
      reorder_method: '',
      reorder_point: '0',
      safety_stock: '0',
      minimum_order_quantity: '1',
      lead_time_days: 0,
      is_critical: false,
      is_active: true,
    },
  });
  const {
    register,
    handleSubmit,
    reset,
    setError,
    watch,
    formState: { errors },
  } = form;
  const initialized = useRef('');
  useEffect(() => {
    const existing = existingQuery.data;
    if (!existing || initialized.current === existing.id || !uoms.data) return;
    reset({
      code: existing.code,
      name: existing.name,
      description: existing.description ?? '',
      category_id: existing.category?.id ?? '',
      unit_of_measure:
        uoms.data.find((unit) => unit.code.toUpperCase() === existing.unit_of_measure.toUpperCase())
          ?.code ?? existing.unit_of_measure,
      standard_cost: existing.standard_cost,
      reorder_method: existing.reorder_method,
      reorder_point: existing.reorder_point,
      safety_stock: existing.safety_stock,
      minimum_order_quantity: existing.minimum_order_quantity,
      lead_time_days: existing.lead_time_days,
      is_critical: existing.is_critical,
      is_active: existing.is_active,
    });
    initialized.current = existing.id;
  }, [existingQuery.data, uoms.data, reset]);
  const values = watch();
  const selectedCategory = categories.data?.find((category) => category.id === values.category_id);
  const classificationLabel =
    selectedCategory?.item_type_label ??
    options.data?.item_types.find((option) => option.value === selectedCategory?.item_type)?.label;
  const keepsLegacyCategory =
    mode === 'edit' && values.category_id === existingQuery.data?.category?.id;
  const classificationUnavailable =
    !!values.category_id && !selectedCategory?.item_type && !keepsLegacyCategory;
  const mutation = useMutation({
    mutationFn: (data: FormValues) => {
      const payload: CreateItemData = {
        ...data,
        reorder_method: data.reorder_method as ReorderMethod,
        description: data.description || undefined,
        minimum_order_quantity: data.minimum_order_quantity || undefined,
      };
      return mode === 'create' ? itemsApi.create(payload) : itemsApi.update(id, payload);
    },
    onSuccess: (item) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'items'] });
      toast.success(mode === 'create' ? 'Item created.' : 'Item updated.');
      navigate(`/inventory/items/${item.id}`);
    },
    onError: (error) => {
      applyServerValidationErrors(error, setError, 'Failed to save the item.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });
  const setupFailed = categories.isError || options.isError || uoms.isError;
  const setupLoading = categories.isPending || options.isPending || uoms.isPending;
  const setupEmpty =
    !setupLoading &&
    !setupFailed &&
    (!categories.data?.length || !uoms.data?.length || !options.data?.reorder_methods.length);
  const header = (
    <PageHeader
      title={mode === 'create' ? 'New item' : `Edit ${existingQuery.data?.code ?? 'item'}`}
      subtitle="Define the inventory identity, base-unit cost and replenishment rules."
      backTo="/inventory/items"
      backLabel="Items"
    />
  );
  if (mode === 'edit' && existingQuery.isPending)
    return (
      <div>
        {header}
        <SkeletonDetail />
      </div>
    );
  if (mode === 'edit' && (existingQuery.isError || !existingQuery.data))
    return (
      <div>
        {header}
        <EmptyState
          icon="alert-circle"
          title="Unable to load item"
          action={
            <Button variant="secondary" onClick={() => existingQuery.refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );
  const unit = values.unit_of_measure || 'base units';
  return (
    <div>
      {header}
      <form
        noValidate
        onSubmit={handleSubmit((data) => mutation.mutate(data), onFormInvalid<FormValues>())}
        className="min-w-0 w-full px-5 py-5 space-y-5"
      >
        <FormDraftBanner safety={safety} inset={false} />
        {existingQuery.data?.classification_needs_review && keepsLegacyCategory && (
          <div
            role="alert"
            className="rounded-md border border-warning bg-warning-bg p-4 text-sm text-warning-fg"
          >
            Category classification needs review. This record remains{' '}
            {existingQuery.data.item_type_label}. Choose a matching category to resolve it; other
            edits preserve its existing type.
          </div>
        )}
        {setupFailed && (
          <div
            role="alert"
            className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning bg-warning-bg p-4 text-sm"
          >
            <span>Unable to load item setup. Your entries are kept.</span>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                categories.refetch();
                options.refetch();
                uoms.refetch();
              }}
            >
              Retry setup
            </Button>
          </div>
        )}
        {setupEmpty && (
          <p role="alert" className="text-sm text-warning-fg">
            Categories, units and item options must be configured before creating an item.
          </p>
        )}
        <fieldset
          disabled={mutation.isPending}
          className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]"
        >
          <div className="min-w-0 space-y-5">
            <Panel title="Item details">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Input
                  label="Code"
                  required
                  fieldSize="lg"
                  {...register('code')}
                  maxLength={30}
                  placeholder="e.g. RM-020"
                  className="font-mono uppercase"
                  autoCapitalize="characters"
                  helper="Unique inventory code. Letters convert to uppercase on save."
                  error={errors.code?.message}
                />
                <Input
                  label="Name"
                  required
                  fieldSize="lg"
                  {...register('name')}
                  maxLength={200}
                  placeholder="e.g. ABS resin — natural"
                  error={errors.name?.message}
                />
                <Select
                  searchable
                  label="Category"
                  required
                  fieldSize="lg"
                  {...register('category_id')}
                  value={values.category_id}
                  disabled={categories.isPending || categories.isError}
                  error={errors.category_id?.message}
                  helper={
                    classificationUnavailable
                      ? 'Configure this category’s item type in Items / Categories before using it.'
                      : classificationLabel
                        ? `${classificationLabel} · determined by category`
                        : 'Category determines the inventory type.'
                  }
                  containerClassName="sm:col-span-2"
                >
                  <option value="">
                    {categories.isPending ? 'Loading categories…' : 'Select category'}
                  </option>
                  {categories.data?.map((category) => (
                    <option
                      key={category.id}
                      value={category.id}
                      disabled={
                        !category.item_type && category.id !== existingQuery.data?.category?.id
                      }
                    >
                      {category.parent_name
                        ? `${category.parent_name} / ${category.name}`
                        : category.name}
                      {!category.item_type ? ' (needs setup)' : ''}
                    </option>
                  ))}
                  {existingQuery.data?.category &&
                    !categories.data?.some(
                      (category) => category.id === existingQuery.data?.category?.id,
                    ) && (
                      <option value={existingQuery.data.category.id}>
                        {existingQuery.data.category.name} (current)
                      </option>
                    )}
                </Select>
                <Textarea
                  label="Description (optional)"
                  {...register('description')}
                  maxLength={1000}
                  rows={3}
                  className="text-base"
                  containerClassName="sm:col-span-2"
                  error={errors.description?.message}
                  placeholder="Specification, grade or other identifying details."
                />
              </div>
            </Panel>
            <Panel title="Base unit and costing">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Select
                  label="Base unit"
                  required
                  fieldSize="lg"
                  {...register('unit_of_measure')}
                  value={values.unit_of_measure}
                  disabled={uoms.isPending || uoms.isError}
                  helper="Stock, BOM costs and purchasing conversions use this unit."
                  error={errors.unit_of_measure?.message}
                >
                  <option value="">{uoms.isPending ? 'Loading units…' : 'Select base unit'}</option>
                  {uoms.data?.map((option) => (
                    <option key={option.id} value={option.code}>
                      {option.code} — {option.name}
                    </option>
                  ))}
                  {values.unit_of_measure &&
                    !uoms.data?.some((option) => option.code === values.unit_of_measure) && (
                      <option value={values.unit_of_measure}>
                        {values.unit_of_measure} (current)
                      </option>
                    )}
                </Select>
                <Input
                  label="Standard cost"
                  readOnly={mode === 'edit' && !can('inventory.costs.approve')}
                  required
                  fieldSize="lg"
                  {...register('standard_cost')}
                  {...numberInputProps()}
                  min={0}
                  step="0.0001"
                  suffix={values.unit_of_measure ? `/ ${values.unit_of_measure}` : undefined}
                  helper={mode === 'edit' && !can('inventory.costs.approve') ? 'Finance approval required to change standard cost.' : 'Estimated cost per base unit; up to 4 decimals.'}
                  className="font-mono tabular-nums"
                  error={errors.standard_cost?.message}
                />
              </div>
              {values.standard_cost !== '' && Number(values.standard_cost) === 0 && (
                <p className="mt-4 text-sm text-warning-fg">
                  Zero cost leaves BOM estimates incomplete. Review the cost before using this item
                  in production.
                </p>
              )}
              {mode === 'edit' && (
                <p className="mt-4 text-xs text-muted">
                  Codes, types and units are protected after linked use. Standard-cost edits refresh
                  affected BOM estimates.
                </p>
              )}
              {(keepsLegacyCategory
                ? existingQuery.data?.item_type
                : selectedCategory?.item_type) === 'finished_good' && (
                <p className="mt-4 text-sm text-muted">
                  For production receipts, use the product part number as this code and match its
                  base unit.
                </p>
              )}
            </Panel>
          </div>
          <div className="min-w-0 space-y-5">
            <Panel title="Replenishment rules">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Select
                  label="Reorder method"
                  required
                  fieldSize="lg"
                  {...register('reorder_method')}
                  value={values.reorder_method}
                  disabled={options.isPending || options.isError}
                  error={errors.reorder_method?.message}
                  containerClassName="sm:col-span-2"
                >
                  <option value="">Select reorder method</option>
                  {options.data?.reorder_methods.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
                <Input
                  label="Reorder point"
                  required
                  fieldSize="lg"
                  {...register('reorder_point')}
                  {...numberInputProps()}
                  min={0}
                  step="0.001"
                  suffix={values.unit_of_measure || undefined}
                  helper="Stock threshold that triggers replenishment."
                  error={errors.reorder_point?.message}
                />
                <Input
                  label="Safety stock"
                  required
                  fieldSize="lg"
                  {...register('safety_stock')}
                  {...numberInputProps()}
                  min={0}
                  step="0.001"
                  suffix={values.unit_of_measure || undefined}
                  helper="Buffer protected from normal planning demand."
                  error={errors.safety_stock?.message}
                />
                <Input
                  label="Minimum order quantity"
                  fieldSize="lg"
                  {...register('minimum_order_quantity')}
                  {...numberInputProps()}
                  min={0.001}
                  step="0.001"
                  suffix={values.unit_of_measure || undefined}
                  helper="Purchase requests round up to a multiple of this quantity."
                  error={errors.minimum_order_quantity?.message}
                />
                <Input
                  label="Lead time (days)"
                  required
                  fieldSize="lg"
                  {...register('lead_time_days')}
                  {...numberInputProps({ decimal: false })}
                  min={0}
                  max={365}
                  helper="Expected supply time; 0 means no lead time."
                  error={errors.lead_time_days?.message}
                />
              </div>
            </Panel>
            <Panel title="Availability and priority">
              <div className="space-y-5">
                <Switch
                  label="Active"
                  className="min-h-11 items-center"
                  description="Available for new purchasing and BOM selection."
                  {...register('is_active')}
                />
                <Switch
                  label="Critical item"
                  className="min-h-11 items-center"
                  description="Flag this item for priority attention."
                  {...register('is_critical')}
                />
              </div>
              <dl className="mt-5 border-t border-default pt-4 text-sm space-y-2">
                <div className="flex justify-between gap-3">
                  <dt className="text-muted">Unit cost</dt>
                  <dd className="font-mono tabular-nums">
                    {formatPeso(values.standard_cost, 'Not entered')} / {unit}
                  </dd>
                </div>
                {mode === 'create' && (
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted">Opening stock</dt>
                    <dd>0</dd>
                  </div>
                )}
              </dl>
              <p className="mt-3 text-xs text-muted">
                Creating an item does not add stock. Record goods receipts or approved adjustments
                separately.
              </p>
            </Panel>
          </div>
        </fieldset>
        {setupLoading && (
          <p role="status" className="text-sm text-muted">
            Loading item setup…
          </p>
        )}
        <FormActions>
          <Button
            type="button"
            variant="secondary"
            className="h-11 md:h-8"
            onClick={() => navigate('/inventory/items')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            className="h-11 md:h-8"
            loading={mutation.isPending}
            disabled={
              mutation.isPending ||
              setupLoading ||
              setupFailed ||
              setupEmpty ||
              classificationUnavailable
            }
          >
            {mode === 'create' ? 'Create item' : 'Save changes'}
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
