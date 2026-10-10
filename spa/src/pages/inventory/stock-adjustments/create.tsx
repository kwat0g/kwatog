import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { stockAdjustmentsApi, stockLevelsApi } from '@/api/inventory/stock';
import { itemsApi } from '@/api/inventory/items';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button, ConfirmDialog, Input, Select, Textarea, Panel } from '@/components/ui';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
import type { Item } from '@/types/inventory';

const schema = z.object({
  item_id: z.string().min(1, 'Item is required.'),
  location_id: z.string().min(1, 'Location is required.'),
  direction: z.enum(['in', 'out'], { message: 'Select an adjustment direction.' }),
  reason_code: z.string().min(1, 'Select a reason code.'),
  quantity: z.string().regex(/^\d+(\.\d{1,3})?$/, 'Use up to 3 decimal places.').refine(v => Number(v) > 0, 'Quantity must be greater than zero.'),
  unit_cost: z.string().regex(/^(\d+(\.\d{1,4})?)?$/, 'Use up to 4 decimal places.').optional(),
  reason: z.string().trim().min(10, 'Reason must be at least 10 characters.').max(500),
}).superRefine((values, context) => {
  if (values.direction === 'in' && !values.unit_cost) {
    context.addIssue({ code: 'custom', path: ['unit_cost'], message: 'Enter a unit cost for this new stock location.' });
  }
});
type Values = z.infer<typeof schema>;

async function itemStock(itemId: string) {
  const first = await stockLevelsApi.list({ item_id: itemId, page: 1, per_page: 100 });
  const rest = await Promise.all(Array.from({ length: Math.max(0, first.meta.last_page - 1) }, (_, index) =>
    stockLevelsApi.list({ item_id: itemId, page: index + 2, per_page: 100 })));
  return [...first.data, ...rest.flatMap(page => page.data)];
}
export default function CreateStockAdjustmentPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [pendingValues, setPendingValues] = useState<Values | null>(null);
  const [retryValues, setRetryValues] = useState<Values | null>(null);
  const [itemSearch, setItemSearch] = useState('');
  const selectedItem = useRef<Item>();
  const request = useRef<{ payload: string; key: string }>();
  const previousSelection = useRef({ item: '', location: '' });
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { item_id: '', location_id: '', reason_code: '', quantity: '', unit_cost: '', reason: '' },
  });
  const { register, handleSubmit, setError, setValue, getValues, watch, formState: { errors } } = form;
  const itemId = watch('item_id');
  const locationId = watch('location_id');
  const direction = watch('direction');
  const quantity = watch('quantity');
  const unitCost = watch('unit_cost');

  const items = useQuery({
    queryKey: ['inventory', 'items', { per_page: 100, is_active: 'true', search: itemSearch || undefined }],
    queryFn: () => itemsApi.list({ per_page: 100, is_active: 'true', search: itemSearch || undefined }),
  });
  const adjustmentOptions = useQuery({
    queryKey: ['inventory', 'stock-adjustments', 'options'], queryFn: stockAdjustmentsApi.options, staleTime: 300_000,
  });
  const warehouses = useQuery({ queryKey: ['inventory', 'warehouse', 'tree'], queryFn: () => warehouseApi.tree() });
  const stock = useQuery({
    queryKey: ['inventory', 'stock-levels', 'adjustment', itemId], queryFn: () => itemStock(itemId), enabled: !!itemId,
  });
  const currentItem = items.data?.data.find(item => item.id === itemId);
  if (currentItem) selectedItem.current = currentItem;
  const item = selectedItem.current?.id === itemId ? selectedItem.current : undefined;
  const lookups = [items, adjustmentOptions, warehouses, ...(itemId ? [stock] : [])];
  const lookupFailed = lookups.some(query => query.isError);
  const lookupPending = lookups.some(query => query.isFetching);
  const allLocations = (warehouses.data ?? []).filter(warehouse => warehouse.is_active).flatMap(warehouse =>
    (warehouse.zones ?? []).flatMap(zone => (zone.locations ?? []).filter(location =>
      location.is_active && (direction !== 'in' || !location.is_blocked)).map(location => ({
      id: location.id, label: [warehouse.code, zone.code, location.code].join('-'), warehouse: warehouse.name,
      stock: stock.data?.find(level => level.location?.id === location.id),
    }))));
  const stockedLocations = allLocations.filter(location => Number(location.stock?.quantity) > 0);
  const locations = itemId && stock.isSuccess ? (direction === 'in' ? allLocations : stockedLocations) : [];
  const selectedLocation = locations.find(location => location.id === locationId);
  const level = selectedLocation?.stock;
  const unit = item?.unit_of_measure ?? level?.item?.unit_of_measure ?? '';
  const pieces = /^(PC|PCS|PIECE|PIECES|EA|EACH|UNIT|UNITS|SET|SETS)$/i.test(unit);
  const removable = Math.max(0, Number(level?.available ?? 0) - Number(level?.quality_held_quantity ?? 0));
  const quantityError = quantity && pieces && !Number.isInteger(Number(quantity))
    ? 'Piece quantities must be whole numbers.'
    : quantity && direction === 'out' && Number(quantity) > removable
      ? 'Only ' + formatQuantity(removable) + ' ' + unit + ' can be removed from this location.' : undefined;
  const cost = direction === 'out' ? level?.weighted_avg_cost : unitCost;
  const estimatedValue = quantity && cost !== undefined && cost !== '' ? Number(quantity) * Number(cost) : null;
  const approvalThreshold = Number(adjustmentOptions.data?.approval_threshold ?? 0);
  const needsApproval = estimatedValue !== null && approvalThreshold > 0 && estimatedValue > approvalThreshold;
  const blocked = !!retryValues || lookupFailed || lookupPending || !!quantityError || !itemId || (direction === 'out' && (!level || removable <= 0));

  useEffect(() => {
    const previous = previousSelection.current;
    const changedItem = previous.item !== itemId;
    if (changedItem) {
      // Validate restored draft locations against fresh stock; clear locations carried from another item.
      if (previous.item) setValue('location_id', '');
      setValue('unit_cost', '');
      previousSelection.current = { item: itemId, location: '' };
    }
    if (!itemId || !stock.isSuccess || !warehouses.isSuccess) return;
    const active = getValues('location_id');
    const valid = locations.find(location => location.id === active);
    if (!valid) {
      const automatic = stockedLocations.length === 1 ? stockedLocations[0].id : '';
      setValue('location_id', automatic);
      setValue('unit_cost', stock.data.find(level => level.location?.id === automatic)?.weighted_avg_cost ?? '');
      previousSelection.current.location = automatic;
    } else if (changedItem || previousSelection.current.location !== active || direction === 'out') {
      setValue('unit_cost', valid.stock?.weighted_avg_cost ?? '');
      previousSelection.current.location = active;
    }
  }, [itemId, locationId, direction, stock.data, stock.isSuccess, warehouses.data, warehouses.isSuccess, getValues, setValue]); // eslint-disable-line react-hooks/exhaustive-deps

  const mutation = useMutation({
    mutationFn: (values: Values) => {
      const payload = {
        item_id: values.item_id, location_id: values.location_id, direction: values.direction,
        quantity: values.quantity, reason_code: values.reason_code, reason: values.reason.trim(),
        ...(values.direction === 'in' ? { unit_cost: values.unit_cost } : {}),
      };
      const serialized = JSON.stringify(payload);
      if (request.current?.payload !== serialized) request.current = { payload: serialized, key: crypto.randomUUID() };
      return stockAdjustmentsApi.create(payload, request.current.key);
    },
    onSuccess: adjustment => {
      setPendingValues(null);
      setRetryValues(null);
      void queryClient.invalidateQueries({ queryKey: ['inventory'] });
      toast.success(adjustment.status === 'pending'
        ? 'Adjustment submitted for Finance approval. Stock has not changed.' : 'Stock adjustment recorded.');
      navigate(adjustment.status === 'pending' ? '/inventory/stock-adjustments?status=pending' : '/inventory/stock-levels?view=movements');
    },
    onError: (error, values) => {
      setPendingValues(null);
      // A lost response may follow a committed movement; retry the original request even if stock changed.
      setRetryValues(error instanceof AxiosError && error.response && error.response.status < 500 ? null : values);
      applyServerValidationErrors(error, setError, 'Failed to record adjustment.');
      void stock.refetch();
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return <div>
    <PageHeader title="Stock adjustment" subtitle="Correct stock at a specific location with a recorded reason."
      backTo="/inventory/stock-adjustments" backLabel="Stock adjustments" />
    <FormDraftBanner safety={safety} />
    <form noValidate className="px-5 py-4 space-y-4" onSubmit={handleSubmit(values => {
      if (!blocked && !mutation.isPending) setPendingValues(values);
    }, onFormInvalid<Values>())}>
      {retryValues && <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-warning-fg">
        Submission result could not be confirmed. Retry this adjustment to check its result before starting another.
        <Button type="button" variant="secondary" size="sm" disabled={mutation.isPending}
          onClick={() => mutation.mutate(retryValues)}>Retry adjustment</Button>
      </div>}
      {lookupFailed && <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-danger-fg">
        Stock adjustment options could not be loaded.
        <Button type="button" variant="secondary" size="sm" onClick={() => lookups.forEach(query => {
          if (query.isError) void query.refetch();
        })}>Retry options</Button>
      </div>}
      <fieldset disabled={!!retryValues || mutation.isPending} className="min-w-0">
      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_20rem] gap-4 items-start">
        <div className="min-w-0 space-y-4">
          <Panel title="Stock and location">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Select searchable label="Item" required loading={items.isFetching} onSearch={setItemSearch}
                {...register('item_id')} value={itemId} error={errors.item_id?.message}>
                <option value="">Select item…</option>
                {item && !currentItem && <option value={item.id}>{item.code} — {item.name}</option>}
                {items.data?.data.map(item => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}
              </Select>
              <Select searchable label="Location" required {...register('location_id')} value={locationId}
                disabled={!itemId || stock.isFetching} error={errors.location_id?.message} loading={stock.isFetching}
                helper={!itemId ? 'Select an item first.' : stockedLocations.length === 1
                  ? 'Filled from the item’s stocked location.' : stockedLocations.length > 1
                    ? 'Stock exists in multiple locations. Choose the bin to adjust.'
                    : 'No stock found. Choose In and select a receiving location.'}>
                <option value="">Select location…</option>
                {locations.map(location => <option key={location.id} value={location.id}>
                  {location.stock?.location?.full_code ?? location.label} · {location.warehouse}
                </option>)}
              </Select>
            </div>
          </Panel>
          <Panel title="Adjustment details">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Select label="Direction" required {...register('direction')} value={direction ?? ''} error={errors.direction?.message}>
                <option value="">Select direction…</option>
                {adjustmentOptions.data?.directions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
              </Select>
              <Input label="Quantity" required {...register('quantity')} {...numberInputProps({ decimal: !pieces })}
                min={pieces ? 1 : 0.001} step={pieces ? 1 : '0.001'} suffix={unit || undefined}
                helper={pieces ? 'Whole pieces only.' : 'Up to 3 decimal places.'} error={quantityError ?? errors.quantity?.message} />
              <Input label="Unit cost" required={direction === 'in'} {...register('unit_cost')} {...numberInputProps()}
                value={unitCost ?? ''} min={0} step="0.0001" readOnly={direction !== 'in'}
                helper={direction === 'out' ? 'Current weighted-average cost. Rechecked when posted.'
                  : 'Filled from this location’s cost. Enter a cost when no history exists.'} error={errors.unit_cost?.message} />
              <Select label="Reason code" required {...register('reason_code')} value={watch('reason_code')} error={errors.reason_code?.message}>
                <option value="">Select reason…</option>
                {adjustmentOptions.data?.reasons.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
              </Select>
              <Textarea label="Reason (recorded for audit)" required {...register('reason')} rows={3} maxLength={500}
                containerClassName="md:col-span-2" placeholder="Explain why the stock needs correction."
                helper="At least 10 characters." error={errors.reason?.message} />
            </div>
          </Panel>
        </div>
        <Panel title="Adjustment preview">
          <div className="space-y-4 text-sm">
            {!selectedLocation ? <p className="text-muted">Select an item and location to see stock and adjustment impact.</p> : <>
              <p className="font-medium break-words">{item?.code ?? level?.item?.code} · {level?.location?.full_code ?? selectedLocation.label}</p>
              <dl className="space-y-2">
                {[
                  ['On hand', level?.quantity ?? '0'], ['Reserved', level?.reserved_quantity ?? '0'],
                  ['Quality held', level?.quality_held_quantity ?? '0'], ['Can remove', String(removable)],
                ].map(([label, value]) => <div key={label} className="flex justify-between gap-3">
                  <dt className="text-muted">{label}</dt><dd className="font-mono tabular-nums">{formatQuantity(value)} {unit}</dd>
                </div>)}
              </dl>
              <div className="border-t border-default pt-3 space-y-2">
                <div className="flex justify-between gap-3"><span className="text-muted">Stock after posting</span>
                  <span className="font-mono tabular-nums">{quantity && direction && !quantityError
                    ? formatQuantity(Number(level?.quantity ?? 0) + (direction === 'in' ? 1 : -1) * Number(quantity)) + ' ' + unit : '—'}</span></div>
                <div className="flex justify-between gap-3"><span className="text-muted">Estimated value</span>
                  <span className="font-mono tabular-nums">{formatPeso(estimatedValue)}</span></div>
              </div>
            </>}
            <p className="text-xs text-muted">{needsApproval
              ? 'Finance approval is required. Stock stays unchanged until approved.'
              : approvalThreshold > 0 ? 'Adjustments above ' + formatPeso(approvalThreshold) + ' require Finance approval.'
                : 'Valid adjustments post immediately.'}</p>
          </div>
        </Panel>
      </div>
      </fieldset>
      <FormActions>
        <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={() => navigate('/inventory/stock-adjustments')}>Cancel</Button>
        <Button type="submit" variant="primary" disabled={blocked || mutation.isPending}>Review adjustment</Button>
      </FormActions>
    </form>
    <ConfirmDialog isOpen={!!pendingValues} onClose={() => setPendingValues(null)}
      onConfirm={() => { if (pendingValues && !blocked && !mutation.isPending) mutation.mutate(pendingValues); }}
      title="Submit stock adjustment?" confirmLabel="Submit adjustment" pending={mutation.isPending} disabled={blocked}
      variant={pendingValues?.direction === 'out' ? 'danger' : 'primary'} description={pendingValues && <div className="space-y-2">
        <p>{item?.code ?? level?.item?.code} — {item?.name ?? level?.item?.name}</p>
        <p>{selectedLocation?.stock?.location?.full_code ?? selectedLocation?.label}</p>
        <p>{pendingValues.direction === 'in' ? 'Increase' : 'Decrease'} by {pendingValues.quantity} {unit} · {formatPeso(estimatedValue)}</p>
        <p>{needsApproval ? 'Stock stays unchanged until Finance approves.' : 'Stock changes when the adjustment is posted. Higher-value adjustments may require Finance approval.'}</p>
      </div>} />
  </div>;
}
