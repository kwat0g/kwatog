import { useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { numberInputProps } from '@/lib/numberInput';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
import { localIsoDate } from '@/lib/formatDate';
const itemSchema = z.object({
  item_id: z.string().min(1, 'Item required'),
  location_id: z.string().min(1, 'Location required'),
  quantity_issued: z
    .string()
    .regex(/^\d+(\.\d{1,3})?$/, 'Use up to 3 decimal places.')
    .refine((v) => Number(v) > 0, 'Must be > 0'),
  issued_uom_code: z.string().max(20).optional().or(z.literal('')),
  material_reservation_id: z.string().optional().or(z.literal('')),
  lot_number: z.string().max(50).optional().or(z.literal('')),
  remarks: z.string().max(200).optional().or(z.literal('')),
});

const schema = z
  .object({
    work_order_id: z.string().optional().or(z.literal('')),
    reference_text: z.string().max(200).optional().or(z.literal('')),
    issued_date: z.string().min(1, 'Issued date required'),
    remarks: z.string().max(1000).optional().or(z.literal('')),
    items: z.array(itemSchema).min(1, 'Add at least one line'),
  })
  .refine((d) => !!d.work_order_id || !!d.reference_text, {
    message: 'Either a work order or a free-text reference is required.',
    path: ['reference_text'],
  });

type FormValues = z.infer<typeof schema>;

const blankLine = {
  item_id: '',
  location_id: '',
  quantity_issued: '',
  issued_uom_code: '',
  material_reservation_id: '',
  lot_number: '',
  remarks: '',
};
const EMPTY_FORM_LINES: FormValues['items'] = [];

export default function CreateMaterialIssuePage() {
  const nav = useNavigate();
  const qc = useQueryClient();
  const [search] = useSearchParams();
  const idempotencyKey = useRef(crypto.randomUUID());

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      work_order_id: search.get('work_order_id') ?? '',
      reference_text: '',
      issued_date: localIsoDate(),
      remarks: '',
      items: [{ ...blankLine }],
    },
  });
  const {
    register,
    control,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = form;
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const selectedWorkOrderId = useWatch({ control, name: 'work_order_id' }) ?? '';
  const watchedLines = useWatch({ control, name: 'items' }) ?? EMPTY_FORM_LINES;

  const [itemSearch, setItemSearch] = useState('');
  const [selectedLabels, setSelectedLabels] = useState<Record<string, string>>({});
  const [selectedUnits, setSelectedUnits] = useState<Record<string, string>>({});
  const issueOptionsQuery = useQuery({
    queryKey: ['inventory', 'material-issues', 'options'],
    queryFn: () => materialIssuesApi.options(),
  });
  const issueOptions = issueOptionsQuery.data;
  const woList = issueOptions?.work_orders ?? [];

  const {
    data: itemOpts = [],
    isFetching: itemsLoading,
    isError: itemsError,
    refetch: refetchItems,
  } = useQuery({
    queryKey: ['inventory', 'items', 'for-mis', itemSearch],
    queryFn: () =>
      itemsApi
        .list({ per_page: 50, is_active: true, search: itemSearch || undefined })
        .then((response) => response.data),
  });

  const sourceQueries = useQueries({
    queries: fields.map((_, idx) => {
      const itemId = watchedLines[idx]?.item_id ?? '';
      return {
        queryKey: [
          'inventory',
          'material-issues',
          'sources',
          selectedWorkOrderId || null,
          itemId || null,
        ],
        queryFn: () =>
          materialIssuesApi.options({
            ...(selectedWorkOrderId ? { work_order_id: selectedWorkOrderId } : {}),
            item_id: itemId,
          }),
        enabled: !!itemId,
      };
    }),
  });

  const workOrderIsIneligible =
    !!selectedWorkOrderId &&
    !!issueOptions &&
    !woList.some((workOrder) => workOrder.id === selectedWorkOrderId);
  const lookupPending =
    itemsLoading ||
    issueOptionsQuery.isFetching ||
    fields.some(
      (_, idx) =>
        !!watchedLines[idx]?.item_id &&
        (sourceQueries[idx]?.isFetching || !sourceQueries[idx]?.data),
    );
  const lookupFailed =
    itemsError ||
    issueOptionsQuery.isError ||
    fields.some((_, idx) => !!watchedLines[idx]?.item_id && sourceQueries[idx]?.isError);
  const lineEligibilityErrors = fields.flatMap((_, idx) => {
    const line = watchedLines[idx];
    const lookup = sourceQueries[idx]?.data;
    if (!line?.item_id || !lookup) return [];
    const reservations = lookup.reservations.filter(
      (reservation) => reservation.location_id === line.location_id,
    );
    const selectedReservation =
      line.material_reservation_id === 'available'
        ? undefined
        : reservations.find((reservation) => reservation.id === line.material_reservation_id);
    const source = lookup.sources.find((candidate) => candidate.location_id === line.location_id);
    const quantity = Number(line.quantity_issued || 0);
    const baseUom =
      itemOpts.find((item) => item.id === line.item_id)?.unit_of_measure ?? selectedUnits[line.item_id] ?? 'base units';
    if (!source)
      return [
        `Line ${idx + 1}: choose an active source location with available or reserved stock.`,
      ];
    if (
      reservations.length === 1 &&
      !selectedReservation &&
      line.material_reservation_id !== 'available'
    )
      return [`Line ${idx + 1}: matching reservation is still being selected.`];
    if (
      reservations.length > 1 &&
      !selectedReservation &&
      line.material_reservation_id !== 'available'
    )
      return [`Line ${idx + 1}: choose which work-order reservation to issue.`];
    if (line.material_reservation_id === 'available' && (!source || Number(source.available) <= 0))
      return [`Line ${idx + 1}: no unreserved stock is available at this location.`];
    if (
      !line.issued_uom_code &&
      selectedReservation &&
      quantity > Number(selectedReservation.quantity)
    )
      return [
        `Line ${idx + 1}: quantity exceeds the ${selectedReservation.quantity} ${baseUom} remaining reservation.`,
      ];
    if (
      !line.issued_uom_code &&
      !selectedReservation &&
      source &&
      quantity > Number(source.available)
    )
      return [
        `Line ${idx + 1}: only ${source.available} ${baseUom} is available at this location.`,
      ];
    return [];
  });

  const canSubmit = (values: FormValues): boolean => {
    if (lookupPending || lookupFailed || workOrderIsIneligible || lineEligibilityErrors.length > 0)
      return false;
    return values.items.every((item, idx) => {
      const lookup = sourceQueries[idx]?.data;
      if (!lookup) return false;
      const reservations = lookup.reservations.filter(
        (reservation) => reservation.location_id === item.location_id,
      );
      const selectedReservation =
        item.material_reservation_id === 'available'
          ? undefined
          : reservations.find((reservation) => reservation.id === item.material_reservation_id);
      if (
        reservations.length > 1 &&
        !selectedReservation &&
        item.material_reservation_id !== 'available'
      )
        return false;
      if (
        reservations.length === 1 &&
        !selectedReservation &&
        item.material_reservation_id !== 'available'
      )
        return false;
      if (
        item.material_reservation_id &&
        item.material_reservation_id !== 'available' &&
        !selectedReservation
      )
        return false;
      const source = lookup.sources.find((candidate) => candidate.location_id === item.location_id);
      if (!source) return false;
      if (item.material_reservation_id === 'available' && Number(source.available) <= 0)
        return false;
      const quantity = Number(item.quantity_issued);
      if (
        !item.issued_uom_code &&
        selectedReservation &&
        quantity > Number(selectedReservation.quantity)
      )
        return false;
      if (
        !item.issued_uom_code &&
        !selectedReservation &&
        (!source || quantity > Number(source.available))
      )
        return false;
      return true;
    });
  };

  const mutation = useMutation({
    mutationFn: (v: FormValues) =>
      materialIssuesApi.create(
        {
          work_order_id: v.work_order_id ? v.work_order_id : null,
          issued_date: v.issued_date,
          reference_text: v.reference_text || undefined,
          remarks: v.remarks || undefined,
          items: v.items.map((i) => ({
            item_id: i.item_id,
            location_id: i.location_id,
            material_reservation_id:
              i.material_reservation_id && i.material_reservation_id !== 'available'
                ? i.material_reservation_id
                : undefined,
            quantity_issued: i.quantity_issued,
            issued_uom_code: i.issued_uom_code || undefined,
            lot_number: i.lot_number || undefined,
            remarks: i.remarks || undefined,
          })),
        },
        idempotencyKey.current,
      ),
    onSuccess: (slip) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'material-issues'] });
      toast.success(`Material issue ${slip.slip_number} created.`);
      nav(`/inventory/material-issues/${slip.id}`);
    },
    onError: (e) => {
      applyServerValidationErrors(e, setError, 'Failed to record the material issue.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <PageShell className="flex flex-col gap-4 pb-4">
      <PageHeader
        inset={false}
        title="New material issue"
        backTo="/inventory/material-issues"
        backLabel="Material issues"
      />
      <FormDraftBanner safety={safety} />
      {lookupFailed && (
        <div role="alert" className="min-w-0 text-sm text-danger-fg">
          Inventory options could not be loaded. Retry before recording an issue.
          <Button
            type="button"
            variant="secondary"
            size="sm"
            className="ml-2"
            onClick={() => {
              void issueOptionsQuery.refetch();
              void refetchItems();
              sourceQueries.forEach((query) => {
                void query.refetch();
              });
            }}
          >
            Retry
          </Button>
        </div>
      )}
      {workOrderIsIneligible && (
        <p role="alert" className="min-w-0 text-sm text-danger-fg">
          This work order is no longer available for material issue. Choose a confirmed,
          in-progress, or paused work order, or clear it.
        </p>
      )}

      <form
        onSubmit={handleSubmit((d) => {
          if (!canSubmit(d)) {
            toast.error(
              'Refresh inventory options or resolve the line quantity/reservation before recording this issue.',
            );
            return;
          }
          mutation.mutate(d);
        }, onFormInvalid<FormValues>())}
        className="min-w-0 w-full space-y-4"
      >
        <Panel title="Reference">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <Select
              searchable
              label="Work order"
              value={selectedWorkOrderId}
              {...register('work_order_id', {
                onChange: () =>
                  fields.forEach((_, idx) =>
                    setValue(`items.${idx}.material_reservation_id`, '', { shouldValidate: true }),
                  ),
              })}
              error={errors.work_order_id?.message}
            >
              <option value="">— None (use reference) —</option>
              {workOrderIsIneligible && (
                <option value={selectedWorkOrderId}>Work order unavailable — choose another</option>
              )}
              {woList.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.wo_number} —{' '}
                  {w.status === 'in_progress'
                    ? 'In progress'
                    : w.status === 'paused'
                      ? 'Paused'
                      : 'Confirmed'}
                  {w.product_part_number ? ` — ${w.product_part_number}` : ''}
                </option>
              ))}
            </Select>
            <Input
              label="Issued date"
              type="date"
              required
              {...register('issued_date')}
              error={errors.issued_date?.message}
            />
            <Input
              label="Reference"
              maxLength={200}
              placeholder="Free-text (if no WO)"
              {...register('reference_text')}
              error={errors.reference_text?.message}
            />
          </div>
          <div className="mt-3">
            <Textarea
              label="Remarks"
              rows={2}
              maxLength={1000}
              placeholder="Optional"
              {...register('remarks')}
              error={errors.remarks?.message}
            />
          </div>
        </Panel>

        <Panel title="Line items">
          {lineEligibilityErrors.length > 0 && (
            <div role="alert" className="mb-3 text-sm text-danger-fg">
              {lineEligibilityErrors.join(' ')}
            </div>
          )}
          <div className="border border-default rounded-md overflow-hidden">
            <div className="hidden lg:grid lg:grid-cols-12 gap-2 h-row px-3 bg-subtle text-table-header text-muted font-semibold border-b border-default items-center">
              <div className="col-span-4">Item</div>
              <div className="col-span-3">Location</div>
              <div className="col-span-2 text-right">Qty issued</div>
              <div className="col-span-2">Remarks</div>
              <div className="col-span-1" />
            </div>
            {fields.map((field, idx) => {
              const line = watchedLines[idx];
              const lookup = sourceQueries[idx]?.data;
              const sourceOptions = lookup?.sources ?? [];
              const lineReservations = (lookup?.reservations ?? []).filter(
                (reservation) => reservation.location_id === line?.location_id,
              );
              const selectedReservation = lineReservations.find(
                (reservation) => reservation.id === line?.material_reservation_id,
              );
              const selectedSource = sourceOptions.find(
                (source) => source.location_id === line?.location_id,
              );
              const quantityLimit = selectedReservation?.quantity ?? selectedSource?.available;
              const baseUom =
                itemOpts.find((item) => item.id === line?.item_id)?.unit_of_measure ?? selectedUnits[line?.item_id ?? ''] ?? 'base units';
              return (
                <div
                  key={field.id}
                  className="grid grid-cols-1 lg:grid-cols-12 gap-2 px-3 py-2 border-b border-subtle items-start"
                >
                  <div className="lg:col-span-4">
                    <SearchableSelect
                      aria-label={`Item for line ${idx + 1}`}
                      required
                      value={line?.item_id ?? ''}
                      selectedLabel={selectedLabels[line?.item_id ?? '']}
                      loading={itemsLoading}
                      onSearch={setItemSearch}
                      options={itemOpts.map((it) => ({
                        value: it.id,
                        label: `${it.code} — ${it.name}`,
                      }))}
                      onChange={(value) => {
                        const item = itemOpts.find((it) => it.id === value);
                        if (item) setSelectedUnits(units => ({...units, [value]: item.unit_of_measure}));
                        if (item)
                          setSelectedLabels((labels) => ({
                            ...labels,
                            [value]: `${item.code} — ${item.name}`,
                          }));
                        setValue(`items.${idx}.item_id`, value, { shouldValidate: true });
                        setValue(`items.${idx}.location_id`, '');
                        setValue(`items.${idx}.material_reservation_id`, '');
                      }}
                      error={errors.items?.[idx]?.item_id?.message}
                    />
                    <Input
                      fieldSize="sm"
                      className="mt-1"
                      aria-label={`Lot number for line ${idx + 1}`}
                      placeholder="Lot (optional)"
                      maxLength={50}
                      {...register(`items.${idx}.lot_number` as const)}
                      error={errors.items?.[idx]?.lot_number?.message}
                    />
                  </div>
                  <div className="lg:col-span-3">
                    <SearchableSelect
                      aria-label={`Location for line ${idx + 1}`}
                      fieldSize="md"
                      required
                      value={line?.location_id ?? ''}
                      disabled={
                        !line?.item_id ||
                        sourceQueries[idx]?.isFetching ||
                        sourceQueries[idx]?.isError
                      }
                      options={sourceOptions.map((source) => ({
                        value: source.location_id,
                        label: `${source.label} — ${source.available} ${baseUom} available${
                          source.reserved_for_work_order !== '0.000'
                            ? ` / ${source.reserved_for_work_order} reserved for this WO`
                            : ''
                        }${source.is_blocked ? ' (blocked; outbound only)' : ''}`,
                      }))}
                      onChange={(value) => {
                        const reservations = (sourceQueries[idx]?.data?.reservations ?? [])
                          .filter((reservation) => reservation.location_id === value);
                        setValue(`items.${idx}.location_id`, value, {
                          shouldDirty: true,
                          shouldValidate: true,
                        });
                        setValue(
                          `items.${idx}.material_reservation_id`,
                          reservations.length === 1 ? reservations[0].id : '',
                          {
                            shouldDirty: true,
                            shouldValidate: true,
                          },
                        );
                      }}
                      error={errors.items?.[idx]?.location_id?.message}
                    />
                    {(lineReservations.length > 1 ||
                      (lineReservations.length === 1 &&
                        Number(selectedSource?.available ?? 0) > 0)) && (
                      <SearchableSelect
                        label="Issue from"
                        fieldSize="sm"
                        className="mt-1"
                        value={line?.material_reservation_id ?? ''}
                        options={[
                          { value: '', label: 'Choose issue source' },
                          ...(Number(selectedSource?.available ?? 0) > 0
                            ? [{
                                value: 'available',
                                label: `Available stock (${selectedSource?.available} ${baseUom})`,
                              }]
                            : []),
                          ...lineReservations.map((reservation) => ({
                            value: reservation.id,
                            label: `${reservation.quantity} reserved${
                              reservation.reserved_at ? ` • ${reservation.reserved_at}` : ''
                            }`,
                          })),
                        ]}
                        onChange={(value) =>
                          setValue(`items.${idx}.material_reservation_id`, value, {
                            shouldDirty: true,
                            shouldValidate: true,
                          })
                        }
                        error={errors.items?.[idx]?.material_reservation_id?.message}
                      />
                    )}
                    {selectedReservation && (
                      <p className="mt-1 text-xs text-muted">
                        Using this work order’s {selectedReservation.quantity} reserved at this
                        location.
                      </p>
                    )}
                    {sourceQueries[idx]?.isError && (
                      <p role="alert" className="mt-1 text-xs text-danger-fg">
                        Source options failed to load. Use Retry above.
                      </p>
                    )}
                    {sourceQueries[idx]?.data && sourceOptions.length === 0 && line?.item_id && (
                      <p className="mt-1 text-xs text-muted">
                        No eligible stock for this item and work order.
                      </p>
                    )}
                    {selectedReservation && (
                      <p className="mt-1 text-xs text-muted">
                        {selectedReservation.quantity} {baseUom} remain on this work-order
                        reservation. Enter no more than this amount; add a separate line for other
                        stock.
                      </p>
                    )}
                    {!selectedReservation && selectedSource && (
                      <p className="mt-1 text-xs text-muted">
                        {selectedSource.available} {baseUom} are available here.
                      </p>
                    )}
                  </div>
                  <div className="lg:col-span-2">
                    <Input
                      aria-label={`Quantity issued for line ${idx + 1}`}
                      max={line?.issued_uom_code ? undefined : quantityLimit}
                      placeholder="0"
                      className="font-mono tabular-nums text-right"
                      {...numberInputProps()}
                      step="0.001"
                      {...register(`items.${idx}.quantity_issued` as const)}
                      error={errors.items?.[idx]?.quantity_issued?.message}
                    />
                    <Input
                      fieldSize="sm"
                      className="mt-1"
                      aria-label={`Unit of measure for line ${idx + 1}`}
                      placeholder="Base unit"
                      value={baseUom}
                      readOnly
                      maxLength={20}
                      error={errors.items?.[idx]?.issued_uom_code?.message}
                    />
                  </div>
                  <div className="lg:col-span-2">
                    <Input
                      aria-label={`Remarks for line ${idx + 1}`}
                      placeholder="Optional"
                      maxLength={200}
                      {...register(`items.${idx}.remarks` as const)}
                      error={errors.items?.[idx]?.remarks?.message}
                    />
                  </div>
                  <div className="lg:col-span-1 flex justify-end pt-1">
                    {fields.length > 1 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        iconOnly
                        icon={<LuTrash2 size={14} />}
                        aria-label="Remove line"
                        onClick={() => remove(idx)}
                        className="text-muted hover:text-danger-fg"
                      />
                    )}
                  </div>
                </div>
              );
            })}
          </div>
          <div className="mt-3">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<LuPlus size={14} />}
              onClick={() => append({ ...blankLine })}
            >
              Add line
            </Button>
          </div>
        </Panel>

        <FormActions>
          <Button
            type="button"
            variant="secondary"
            onClick={() => nav('/inventory/material-issues')}
            disabled={mutation.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={
              isSubmitting ||
              mutation.isPending ||
              lookupPending ||
              lookupFailed ||
              workOrderIsIneligible ||
              lineEligibilityErrors.length > 0
            }
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Saving…' : 'Create issue'}
          </Button>
        </FormActions>
      </form>
    </PageShell>
  );
}
