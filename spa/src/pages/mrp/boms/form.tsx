import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import { uomsApi } from '@/api/inventory/uoms';
import { productsApi } from '@/api/crm/products';
import { itemsApi } from '@/api/inventory/items';
import { bomsApi, type CreateBomData } from '@/api/mrp/boms';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
import { usePermission } from '@/hooks/usePermission';

const identity = z.object({
  id: z.string(),
  code: z.string(),
  name: z.string(),
  unit_of_measure: z.string(),
});
const productIdentity = z.object({
  id: z.string(),
  part_number: z.string(),
  name: z.string(),
  unit_of_measure: z.string(),
});
const schema = z
  .object({
    product_id: z.string().min(1, 'Select a finished product.'),
    _product: productIdentity.optional(),
    cost_batch_size: z
      .string()
      .regex(/^\d+(\.\d{1,3})?$/, 'Use up to 3 decimals.')
      .refine(
        (value) => Number(value) >= 1 && Number(value) <= 1000000,
        'Cost batch size must be between 1 and 1,000,000.',
      ),
    items: z
      .array(
        z.object({
          item_id: z.string().min(1, 'Select a component.'),
          quantity_per_unit: z
            .string()
            .regex(/^\d+(\.\d{1,4})?$/, 'Use up to 4 decimals.')
            .refine((value) => Number(value) > 0, 'Quantity must be greater than zero.'),
          unit: z.string().min(1, 'Select a unit.').max(20),
          waste_factor: z
            .string()
            .regex(/^\d+(\.\d{1,2})?$/, 'Use up to 2 decimals.')
            .refine(
              (value) => Number(value) >= 0 && Number(value) <= 50,
              'Waste must be between 0 and 50%.',
            ),
          _item: identity.optional(),
        }),
      )
      .min(1, 'Add at least one component.'),
  })
  .superRefine((values, context) => {
    const seen = new Set<string>();
    values.items.forEach((row, index) => {
      if (row.item_id && seen.has(row.item_id))
        context.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['items', index, 'item_id'],
          message: 'This component is already in the BOM. Combine its quantities in one line.',
        });
      seen.add(row.item_id);
    });
  });
type FormValues = z.infer<typeof schema>;
const emptyRow = () => ({ item_id: '', quantity_per_unit: '', unit: '', waste_factor: '0' });

export default function BomFormPage({ mode }: { mode: 'create' | 'edit' }) {
  const { id = '' } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [productSearch, setProductSearch] = useState('');
  const [itemSearch, setItemSearch] = useState('');
  const [lineSearch, setLineSearch] = useState('');
  const existingQuery = useQuery({
    queryKey: ['mrp', 'boms', 'detail', id],
    queryFn: () => bomsApi.show(id),
    enabled: mode === 'edit' && !!id,
  });
  const products = useQuery({
    queryKey: ['crm', 'products', 'bom-lookup', productSearch],
    queryFn: () =>
      productsApi.options({
        per_page: 100,
        is_active: true,
        search: productSearch || undefined,
      }),
    enabled: mode === 'create',
  });
  const items = useQuery({
    queryKey: ['inventory', 'items', 'bom-lookup', itemSearch],
    queryFn: () =>
      itemsApi.catalog({
        per_page: 100,
        is_active: true,
        search: itemSearch || undefined,
      }),
  });
  const uoms = useQuery({
    queryKey: ['inventory', 'uoms'],
    queryFn: uomsApi.list,
    staleTime: 300_000,
  });
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      product_id: mode === 'create' ? searchParams.get('product_id') ?? '' : '',
      cost_batch_size: '1',
      items: [emptyRow()],
    },
  });
  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    setValue,
    watch,
    formState: { errors },
  } = form;
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const values = watch();
  const initialized = useRef('');
  useEffect(() => {
    const bom = existingQuery.data;
    if (!bom || initialized.current === bom.id) return;
    reset({
      product_id: bom.product?.id ?? '',
      _product: bom.product,
      cost_batch_size: bom.cost_batch_size ?? '1',
      items: (bom.items ?? []).map((row) => ({
        item_id: row.item?.id ?? '',
        quantity_per_unit: row.quantity_per_unit,
        unit: row.unit,
        waste_factor: row.waste_factor ?? '0',
        _item: row.item ?? undefined,
      })),
    });
    initialized.current = bom.id;
  }, [existingQuery.data, reset]);
  const selectedProduct = useQuery({
    queryKey: ['crm', 'products', 'bom-selected', values.product_id],
    queryFn: () => productsApi.show(values.product_id),
    enabled: mode === 'create' && !!values.product_id && can('crm.products.view'),
  });
  const product =
    mode === 'edit' ? existingQuery.data?.product : (selectedProduct.data ?? values._product);
  const mutation = useMutation({
    mutationFn: (data: FormValues) => {
      const payload: CreateBomData = {
        product_id: mode === 'edit' ? existingQuery.data!.product!.id : data.product_id,
        cost_batch_size: data.cost_batch_size,
        items: data.items.map((row, index) => ({
          item_id: row.item_id,
          quantity_per_unit: row.quantity_per_unit,
          unit: row.unit,
          waste_factor: row.waste_factor,
          sort_order: index,
        })),
      };
      return mode === 'create' ? bomsApi.create(payload) : bomsApi.update(id, payload);
    },
    onSuccess: (bom) => {
      queryClient.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      queryClient.invalidateQueries({ queryKey: ['crm', 'products'] });
      toast.success(`BOM version ${bom.version} saved.`);
      navigate(`/mrp/boms/${bom.id}`);
    },
    onError: (error) => {
      setLineSearch('');
      applyServerValidationErrors(error, setError, 'Failed to save the BOM.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });
  const title = mode === 'create' ? 'New BOM' : 'Edit BOM';
  const back = mode === 'edit' ? `/mrp/boms/${id}` : '/mrp/boms';
  const header = (
    <PageHeader
      title={title}
      subtitle="Define component quantities for one finished unit. Saving publishes a new version."
      backTo={back}
      backLabel="BOMs"
    />
  );
  const setupFailed = items.isError || uoms.isError || (mode === 'create' && products.isError);
  const setupLoading =
    items.isPending || uoms.isPending || (mode === 'create' && products.isPending);
  const selectedIds = new Set(values.items.map((row) => row.item_id).filter(Boolean));
  const selectedCount = selectedIds.size;
  const invalidLines = values.items.filter(
    (row) =>
      !row.item_id ||
      !(Number(row.quantity_per_unit) > 0) ||
      !row.unit ||
      !(Number(row.waste_factor) >= 0 && Number(row.waste_factor) <= 50),
  ).length;
  const searchText = lineSearch.trim().toLowerCase();
  const shownCount = values.items.filter(
    (row) =>
      !searchText ||
      `${row._item?.code ?? ''} ${row._item?.name ?? ''}`.toLowerCase().includes(searchText),
  ).length;
  const productOptions = products.data?.data ?? [];
  const currentProduct =
    product && !productOptions.some((option) => option.id === product.id) ? [product] : [];
  const pickItem = (index: number, itemId: string) => {
    const item =
      items.data?.data.find((option) => option.id === itemId) ?? values.items[index]._item;
    setValue(`items.${index}.item_id`, itemId, { shouldDirty: true, shouldValidate: true });
    if (item?.id === itemId) {
      const canonicalUnit =
        uoms.data?.find((unit) => unit.code.toUpperCase() === item.unit_of_measure.toUpperCase())
          ?.code ?? item.unit_of_measure;
      setValue(`items.${index}._item`, item, { shouldDirty: true });
      setValue(`items.${index}.unit`, canonicalUnit, { shouldDirty: true, shouldValidate: true });
    } else {
      setValue(`items.${index}._item`, undefined, { shouldDirty: true });
      setValue(`items.${index}.unit`, '', { shouldDirty: true });
    }
  };
  const invalid = onFormInvalid<FormValues>();
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
          title="Unable to load BOM"
          action={
            <Button variant="secondary" onClick={() => existingQuery.refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );
  return (
    <div>
      {header}
      <form
        noValidate
        onSubmit={handleSubmit(
          (data) => mutation.mutate(data),
          (error) => {
            setLineSearch('');
            invalid(error);
          },
        )}
        className="min-w-0 w-full px-5 py-5 space-y-5"
      >
        <FormDraftBanner safety={safety} inset={false} />
        {setupFailed && (
          <div
            role="alert"
            className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning bg-warning-bg p-4 text-sm"
          >
            <span>Unable to load BOM setup. Your component entries are kept.</span>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                items.refetch();
                uoms.refetch();
                if (mode === 'create') products.refetch();
              }}
            >
              Retry setup
            </Button>
          </div>
        )}
        <fieldset disabled={mutation.isPending} className="min-w-0 space-y-5">
          <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,340px)]">
            <Panel title="Finished product">
              {mode === 'create' ? (
                <div className="space-y-4">
                  <input type="hidden" {...register('product_id')} />
                  <SearchableSelect
                    label="Finished product"
                    required
                    value={values.product_id}
                    selectedLabel={product ? `${product.part_number} — ${product.name}` : undefined}
                    options={[...currentProduct, ...productOptions].map((option) => ({
                      value: option.id,
                      label: `${option.part_number} — ${option.name}`,
                    }))}
                    onSearch={setProductSearch}
                    onChange={(value) => {
                      setValue('product_id', value, { shouldDirty: true, shouldValidate: true });
                      setValue(
                        '_product',
                        products.data?.data.find((option) => option.id === value) ??
                          currentProduct.find((option) => option.id === value),
                        { shouldDirty: true },
                      );
                    }}
                    loading={products.isFetching}
                    disabled={products.isError || mutation.isPending}
                    placeholder="Search part number or product name"
                    error={errors.product_id?.message}
                  />
                  {products.data && products.data.meta.total > products.data.data.length && (
                    <p className="text-xs text-muted">
                      Showing {products.data.data.length} of {products.data.meta.total} products.
                      Search to narrow the list.
                    </p>
                  )}
                  {products.data?.data.length === 0 && !products.isPending && (
                    <p className="text-sm text-muted">
                      No matching active products. Try another search.
                    </p>
                  )}
                </div>
              ) : (
                <div className="space-y-2">
                  <input type="hidden" {...register('product_id')} />
                  <p className="font-mono text-sm">{product?.part_number}</p>
                  <p className="text-sm">{product?.name}</p>
                  <p className="text-xs text-muted">
                    Product is fixed for this BOM. Components can be replaced in the new version.
                  </p>
                </div>
              )}
              {product && (
                <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-default pt-4 text-sm">
                  <Chip variant="neutral">Output unit: {product.unit_of_measure}</Chip>
                  {mode === 'edit' && (
                    <Chip variant="neutral">Source version {existingQuery.data?.version}</Chip>
                  )}
                  {can('crm.products.view') && (
                    <Link
                      to={`/crm/products/${product.id}`}
                      className="text-accent hover:underline"
                    >
                      View product
                    </Link>
                  )}
                </div>
              )}
              {mode === 'create' && selectedProduct.data?.active_bom && (
                <p className="mt-3 text-sm text-warning-fg">
                  Saving replaces active version {selectedProduct.data.active_bom.version}. Its
                  definition remains in history. Existing Work Orders keep their assigned version;
                  new Work Orders use the new version.
                </p>
              )}
              {selectedProduct.data?.finished_good_readiness &&
                !selectedProduct.data.finished_good_readiness.ready && (
                  <p className="mt-3 text-sm text-warning-fg">
                    {selectedProduct.data.finished_good_readiness.reason} Fix inventory mapping
                    before production receipts.
                  </p>
                )}
            </Panel>
            <Panel title="Costing setup">
              <Input
                label="Cost batch size"
                required
                fieldSize="lg"
                {...register('cost_batch_size')}
                {...numberInputProps()}
                min={1}
                max={1000000}
                step="0.001"
                error={errors.cost_batch_size?.message}
                suffix={product?.unit_of_measure}
              />
              <p className="mt-3 text-sm text-muted">
                Setup time is spread across this many finished units for per-unit costing.
              </p>
              <dl className="mt-4 space-y-2 border-t border-default pt-4 text-sm">
                <div className="flex justify-between gap-3">
                  <dt className="text-muted">Components selected</dt>
                  <dd className="font-mono tabular-nums">{selectedCount}</dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt className="text-muted">Lines to complete</dt>
                  <dd className="font-mono tabular-nums">{invalidLines}</dd>
                </div>
              </dl>
              <p className="mt-3 text-xs text-muted">
                Quantities below are per one finished unit. Batch size only allocates setup cost.
              </p>
            </Panel>
          </div>
          <Panel
            title="Components"
            noPadding
            actions={
              <Button
                type="button"
                variant="secondary"
                size="sm"
                className="h-11 md:h-7"
                icon={<LuPlus size={14} />}
                onClick={() => {
                  append(emptyRow());
                  setLineSearch('');
                }}
              >
                Add component
              </Button>
            }
          >
            <div className="p-4">
              <Input
                label="Find a line in this BOM"
                fieldSize="lg"
                value={lineSearch}
                onChange={(event) => setLineSearch(event.target.value)}
                placeholder="Filter selected components"
                helper={`${shownCount} of ${fields.length} lines shown. All lines are saved.`}
              />
            </div>
            {items.isFetching && (
              <p role="status" className="px-4 pb-3 text-sm text-muted">
                Loading component choices…
              </p>
            )}
            {!items.isPending && items.data?.data.length === 0 && (
              <p className="px-4 pb-3 text-sm text-muted">
                No matching inventory items. Try another code or name.
              </p>
            )}
            {items.data && items.data.meta.total > items.data.data.length && (
              <p className="px-4 pb-3 text-xs text-muted">
                Showing {items.data.data.length} of {items.data.meta.total} items. Search to find
                other components.
              </p>
            )}
            <div
              className="max-h-[520px] overflow-auto border-t border-default"
              role="region"
              aria-label="BOM component table"
              tabIndex={0}
            >
              <table className={`${tableCls} min-w-[860px] table-fixed`}>
                <thead className="sticky top-0 z-10 bg-elevated">
                  <tr className={theadTrCls}>
                    <Th className="w-10">Line</Th>
                    <Th className="w-[32%]">Component</Th>
                    <Th className="w-[16%]" align="right">
                      Qty / finished unit
                    </Th>
                    <Th className="w-[14%]">Unit</Th>
                    <Th className="w-[13%]" align="right">
                      Waste %
                    </Th>
                    <Th className="w-[15%]" align="right">
                      With waste
                    </Th>
                    <Th className="w-12">
                      <span className="sr-only">Actions</span>
                    </Th>
                  </tr>
                </thead>
                <tbody>
                  {fields.map((field, index) => {
                    const row = values.items[index];
                    const itemOptions = items.data?.data ?? [];
                    const chosen = row._item;
                    const optionsForRow =
                      chosen && !itemOptions.some((option) => option.id === chosen.id)
                        ? [chosen, ...itemOptions]
                        : itemOptions;
                    const quantityWithWaste =
                      Number(row.quantity_per_unit) * (1 + Number(row.waste_factor) / 100);
                    const visible =
                      !searchText ||
                      `${chosen?.code ?? ''} ${chosen?.name ?? ''}`
                        .toLowerCase()
                        .includes(searchText);
                    const baseUnit = chosen?.unit_of_measure;
                    return (
                      <tr key={field.id} className={trCls} hidden={!visible}>
                        <Td mono className="align-top py-3 text-muted">
                          {index + 1}
                        </Td>
                        <Td className="align-top py-3">
                          <input type="hidden" {...register(`items.${index}.item_id`)} />
                          <SearchableSelect
                            aria-label={`Component for line ${index + 1}`}
                            value={row.item_id}
                            selectedLabel={chosen ? `${chosen.code} — ${chosen.name}` : undefined}
                            options={optionsForRow.map((option) => ({
                              value: option.id,
                              label: `${option.code} — ${option.name}`,
                              disabled: option.id !== row.item_id && selectedIds.has(option.id),
                            }))}
                            onSearch={setItemSearch}
                            onChange={(value) => pickItem(index, value)}
                            loading={items.isFetching}
                            disabled={items.isError || mutation.isPending}
                            placeholder="Search code or name"
                            error={errors.items?.[index]?.item_id?.message}
                          />
                          {baseUnit && (
                            <p className="mt-2 text-xs text-muted">Base unit: {baseUnit}</p>
                          )}
                        </Td>
                        <Td className="align-top py-3">
                          <Input
                            aria-label={`Quantity per unit for line ${index + 1}`}
                            fieldSize="lg"
                            {...register(`items.${index}.quantity_per_unit`)}
                            {...numberInputProps()}
                            min={0.0001}
                            step="0.0001"
                            className="font-mono tabular-nums text-right"
                            placeholder="0.0000"
                            error={errors.items?.[index]?.quantity_per_unit?.message}
                          />
                        </Td>
                        <Td className="align-top py-3">
                          <Input
                            aria-label={`Unit for line ${index + 1}`}
                            fieldSize="lg"
                            {...register(`items.${index}.unit`)}
                            value={row.unit}
                            readOnly
                            placeholder="Automatic"
                            error={errors.items?.[index]?.unit?.message}
                          />
                          {baseUnit &&
                            row.unit &&
                            baseUnit.toUpperCase() !== row.unit.toUpperCase() && (
                              <p className="mt-2 text-xs text-warning-fg">
                                Saved unit; quantities use {row.unit}.
                              </p>
                            )}
                        </Td>
                        <Td className="align-top py-3">
                          <Input
                            aria-label={`Waste percentage for line ${index + 1}`}
                            fieldSize="lg"
                            {...register(`items.${index}.waste_factor`)}
                            {...numberInputProps()}
                            min={0}
                            max={50}
                            step="0.01"
                            className="font-mono tabular-nums text-right"
                            error={errors.items?.[index]?.waste_factor?.message}
                          />
                        </Td>
                        <Td mono align="right" className="align-top py-3">
                          <p className="pt-3">
                            {row.quantity_per_unit && Number.isFinite(quantityWithWaste)
                              ? quantityWithWaste.toLocaleString('en-PH', {
                                  maximumFractionDigits: 4,
                                })
                              : '—'}
                          </p>
                          <p className="mt-2 text-xs text-muted">{row.unit}</p>
                        </Td>
                        <Td className="align-top py-3">
                          <Button
                            type="button"
                            variant="ghost"
                            iconOnly
                            aria-label={`Remove component line ${index + 1}`}
                            icon={<LuTrash2 size={16} />}
                            className="h-11 w-11 text-muted hover:text-danger-fg"
                            onClick={() => remove(index)}
                          />
                        </Td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              {shownCount === 0 && (
                <div className="p-5 text-sm text-muted">
                  {fields.length === 0
                    ? 'Add a component to start the BOM.'
                    : 'No selected lines match this filter.'}
                  {lineSearch && (
                    <Button type="button" variant="ghost" onClick={() => setLineSearch('')}>
                      Clear filter
                    </Button>
                  )}
                </div>
              )}
            </div>
            <div className="border-t border-default p-4 text-xs text-muted">
              Use each component once. UoM is automatic and locked. Existing lines retain their
              saved unit. Waste adds to the required quantity.
            </div>
            {(errors.items?.message || errors.items?.root?.message) && (
              <p role="alert" className="px-4 pb-4 text-sm text-danger-fg">
                {errors.items?.message || errors.items?.root?.message}
              </p>
            )}
          </Panel>
        </fieldset>
        {setupLoading && (
          <p role="status" className="text-sm text-muted">
            Loading BOM setup…
          </p>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
          <p>
            {fields.length} component {fields.length === 1 ? 'line' : 'lines'} · Per-unit quantities
          </p>
          <p>
            {mode === 'edit'
              ? `Source version ${existingQuery.data?.version} remains in history.`
              : 'Costing runs when the new version is saved.'}
          </p>
        </div>
        <FormActions>
          <Button
            type="button"
            variant="secondary"
            className="h-11 md:h-8"
            onClick={() => navigate(back)}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            className="h-11 md:h-8"
            loading={mutation.isPending}
            disabled={mutation.isPending || setupLoading || setupFailed || !uoms.data?.length}
          >
            {mode === 'create' ? 'Save BOM' : 'Save new version'}
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
