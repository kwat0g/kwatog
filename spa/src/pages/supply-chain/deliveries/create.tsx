 /** Sprint 7 — Delivery Create Form. Outbound delivery from a deliverable sales order. */
import { useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm, useFieldArray } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { isAxiosError } from 'axios';
import { useAuthStore } from '@/stores/authStore';
import { LuPlus, LuX } from '@/lib/icons';
import toast from 'react-hot-toast';
import { deliveriesApi, vehiclesApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { LinkButton } from '@/components/ui/LinkButton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';
import { localIsoDate } from '@/lib/formatDate';
import { useDebounce } from '@/hooks/useDebounce';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
// ─── Validation ──────────────────────────────────────────────────────────────

const itemSchema = z.object({
 sales_order_item_id: z.string().min(1, 'Select a line item'),
 quantity: z.coerce
 .number({ invalid_type_error: 'Must be a number' })
 .positive('Must be greater than zero')
 .refine((value) => Math.abs(value * 100 - Math.round(value * 100)) < 1e-8, 'Use at most 2 decimal places'),
 inspection_id: z.string().min(1, 'Select a passed outgoing inspection'),
});

const schema = z.object({
 sales_order_id: z.string().min(1, 'Sales order is required'),
 vehicle_id: z.string().optional(),
 scheduled_date: z.string().min(1, 'Scheduled date is required'),
 notes: z.string().max(2000).optional(),
 items: z.array(itemSchema).min(1, 'Add at least one delivery line'),
});

type FormValues = z.infer<typeof schema>;
const submissionSchema = z.object({ key: z.string().regex(/^delivery-[0-9a-f-]{36}$/i), data: schema });
type Submission = z.infer<typeof submissionSchema>;

function readSubmission(key: string): Submission | null {
 try {
  const result = submissionSchema.safeParse(JSON.parse(localStorage.getItem(key) ?? 'null'));
  return result.success ? result.data : null;
 } catch { return null; }
}

// ─── Component ───────────────────────────────────────────────────────────────

export default function CreateDeliveryPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const userId = useAuthStore((state) => state.user?.id);
 const submissionStorageKey = `ogami:formdraft:delivery-submission:${userId}`;
 const [submission, setSubmission] = useState<Submission | null>(() => readSubmission(submissionStorageKey));
 const idempotencyKey = useRef(submission?.key ?? `delivery-${crypto.randomUUID()}`).current;
 const rememberSubmission = (value: Submission | null) => {
  setSubmission(value);
  try {
   if (value) localStorage.setItem(submissionStorageKey, JSON.stringify(value));
   else localStorage.removeItem(submissionStorageKey);
  } catch { /* In-memory retry remains available when browser storage is disabled. */ }
 };

 const [search, setSearch] = useState('');
 const [page, setPage] = useState(1);
 const debouncedSearch = useDebounce(search, 300);
 const ordersQuery = useQuery({
  queryKey: ['supply-chain', 'deliveries', 'form-options', debouncedSearch, page],
  queryFn: () => deliveriesApi.formOptions({ search: debouncedSearch, page }),
 });
 const soList = ordersQuery.data?.sales_orders ?? [];
 const soLoading = ordersQuery.isLoading;
 const soError = ordersQuery.isError;
 const vehiclesQuery = useQuery({
  queryKey: ['supply-chain', 'vehicles', 'available'],
  queryFn: () => vehiclesApi.list({ status: 'available', per_page: 100 }),
 });
 const vehicleList = vehiclesQuery.data?.data ?? [];
 const vehiclesLoading = vehiclesQuery.isLoading;

 // ── Form ──
  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: submission?.data ?? {
 sales_order_id: '',
 vehicle_id: '',
 scheduled_date: localIsoDate(),
 notes: '',
 items: [{ sales_order_item_id: '', quantity: undefined as unknown as number, inspection_id: '' }],
 },
 });
 const {
 register,
 control,
 handleSubmit,
 watch,
 setError,
 formState: { errors, isSubmitting },
 } = form;

 const { fields, append, remove, replace } = useFieldArray({ control, name: 'items' });

 // Watch SO selection to populate line item options.
  const selectedSoId = watch('sales_order_id');

 const selectedOrderQuery = useQuery({
  queryKey: ['supply-chain', 'deliveries', 'form-options', selectedSoId],
  queryFn: () => deliveriesApi.formOptions({ sales_order_id: selectedSoId }),
  enabled: Boolean(selectedSoId),
 });
 const selectedSo = selectedOrderQuery.data?.selected_sales_order;
 const soDetailLoading = selectedOrderQuery.isLoading;
 const soItems = selectedSo?.items?.filter((item) => Number(item.remaining_quantity) > 0) ?? [];
 const inspectionQuery = useQuery({
  queryKey: ['supply-chain', 'deliveries', 'inspection-options', selectedSoId],
  queryFn: () => deliveriesApi.inspectionOptions(selectedSoId),
  enabled: Boolean(selectedSoId),
 });
 const inspectionOptions = inspectionQuery.data ?? [];
 const inspectionOptionsLoading = inspectionQuery.isLoading;

 // ── Mutation ──
 const mutation = useMutation({
 mutationFn: (data: FormValues) => {
  rememberSubmission({ key: idempotencyKey, data });
  return deliveriesApi.create({
 sales_order_id: data.sales_order_id,
 vehicle_id: data.vehicle_id || undefined,
 scheduled_date: data.scheduled_date,
 notes: data.notes || undefined,
 items: data.items.map((i) => ({
 sales_order_item_id: i.sales_order_item_id,
 quantity: i.quantity,
 inspection_id: i.inspection_id,
 })),
  }, idempotencyKey);
 },
 onSuccess: async (delivery) => {
 rememberSubmission(null);
 safety.draftState.discard();
 await qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries'] });
 toast.success('Delivery created');
 navigate(`/supply-chain/deliveries/${delivery.id}`);
 },
 onError: (err) => {
 if (isAxiosError(err) && err.response && err.response.status < 500) rememberSubmission(null);
 applyServerValidationErrors(err, setError, 'Failed to create delivery.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess, draft: !submission && !mutation.isPending });

 // ── Pre-populate driver_id from SO if SO has a delivery address ──
 // (not applicable here — driver comes from fleet, not SO)

 return (
 <div>
 <PageHeader
 title="New delivery"
 backTo="/supply-chain/deliveries"
 backLabel="Deliveries"
 />
      {!submission && <FormDraftBanner safety={safety} />}
 {submission && !mutation.isPending && <div role="alert" className="max-w-3xl mx-auto px-5 py-3 text-sm">
  <p>The last save has not been confirmed. Retry that same delivery to recover it safely, including after a page reload.</p>
  <Button type="button" variant="primary" className="mt-2" onClick={() => mutation.mutate(submission.data)}>Retry last save</Button>
 </div>}

 <form
 onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
 className="max-w-3xl mx-auto px-5 py-4"
 >
 <fieldset disabled={Boolean(submission) || mutation.isPending}>
 {/* ── Sales Order ── */}
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
 Sales order
 </legend>
 <Input label="Find sales order" value={search} placeholder="Search order number"
  onChange={(event) => { setSearch(event.target.value); setPage(1); }} containerClassName="mb-3" />
 <Select
 label="Sales order"
 {...register('sales_order_id', { onChange: () => {
 replace([{ sales_order_item_id: '', quantity: undefined as unknown as number, inspection_id: '' }]);
 form.clearErrors('items');
 } })}
 error={errors.sales_order_id?.message}
 required
 disabled={soLoading || soError}
 >
 <option value="">
 {soLoading
 ? 'Loading sales orders…'
 : soError
 ? 'Failed to load sales orders'
 : '— Select deliverable sales order —'}
 </option>
 {selectedSo && !soList.some((order) => order.id === selectedSo.id) && <option value={selectedSo.id}>{selectedSo.so_number} — {selectedSo.customer?.name}</option>}
 {soList.map((so) => (
 <option key={so.id} value={so.id}>
 {so.so_number}
 {so.customer ? ` — ${so.customer.name}` : ''}
 </option>
 ))}
 </Select>
 {soError && <Button type="button" className="mt-2" onClick={() => void ordersQuery.refetch()}>Retry sales orders</Button>}
 {!soLoading && !soError && soList.length === 0 && <p className="mt-2 text-sm text-muted">No deliverable orders match. Try another order number.</p>}
 {(page > 1 || ordersQuery.data?.has_more) && <div className="flex items-center gap-3 mt-2">
  <Button type="button" disabled={page === 1 || ordersQuery.isFetching} onClick={() => setPage((value) => value - 1)}>Previous orders</Button>
  <span className="text-xs text-muted">Page {page}</span>
  <Button type="button" disabled={!ordersQuery.data?.has_more || ordersQuery.isFetching} onClick={() => setPage((value) => value + 1)}>More orders</Button>
 </div>}
 {selectedSo && !soList.some((order) => order.id === selectedSo.id) && <p className="text-sm text-muted mt-2">Selected: {selectedSo.so_number}</p>}
 {selectedSo && (
 <p className="mt-1.5 text-xs text-muted">
 Customer: {selectedSo.customer?.name ?? '—'}
 </p>
 )}
 </fieldset>

 {/* ── Schedule ── */}
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
 Schedule
 </legend>
 <Input
 label="Scheduled delivery date"
 type="date"
 {...register('scheduled_date')}
 error={errors.scheduled_date?.message}
 required
 />
 </fieldset>

 {/* ── Vehicle (optional) ── */}
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
 Vehicle (optional)
 </legend>
 <Select
 label="Vehicle"
 {...register('vehicle_id')}
 error={errors.vehicle_id?.message}
 disabled={vehiclesLoading}
 >
 <option value="">
 {vehiclesLoading ? 'Loading available vehicles…' : '— No vehicle assigned —'}
 </option>
 {vehicleList.map((v) => (
 <option key={v.id} value={v.id}>
 {v.name} ({v.plate_number})
 </option>
 ))}
 </Select>
 {vehiclesQuery.isError && <div role="alert" className="mt-2 text-sm text-danger-fg">Vehicles could not be loaded. You can assign one after creating the delivery. <Button type="button" onClick={() => void vehiclesQuery.refetch()}>Retry vehicles</Button></div>}
 </fieldset>

 {/* ── Delivery line items ── */}
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
 Delivery items
 </legend>

 {!selectedSoId && (
 <p className="text-xs text-muted px-3 py-2 bg-subtle rounded-md border border-default mb-3">
 Select a sales order above to populate line item choices.
 </p>
 )}

 {errors.items?.root?.message && (
 <p className="text-xs text-danger-fg mb-2">{errors.items.root.message}</p>
 )}

 {selectedSoId && (selectedOrderQuery.isError || inspectionQuery.isError) && <div role="alert" className="text-sm text-danger-fg mb-3">
  Delivery items could not be loaded.
  <Button type="button" onClick={() => { void selectedOrderQuery.refetch(); void inspectionQuery.refetch(); }}>Retry delivery items</Button>
 </div>}
 {selectedSoId && !soDetailLoading && !selectedOrderQuery.isError && !selectedSo && <p role="alert" className="text-sm text-danger-fg mb-3">This order is no longer deliverable. Select another order.</p>}
 <div className="space-y-3">
 {fields.map((field, index) => (
 <div
 key={field.id}
 className="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_120px_auto] gap-2 items-end p-3 bg-subtle rounded-md border border-default"
 >
 {/* SO Item select */}
 <Select
 label="Sales order line"
 required
 {...register(`items.${index}.sales_order_item_id`, { onChange: () => {
  form.setValue(`items.${index}.inspection_id`, '');
  form.clearErrors(`items.${index}.inspection_id`);
 } })}
 disabled={!selectedSoId || soDetailLoading || selectedOrderQuery.isError}
 error={errors.items?.[index]?.sales_order_item_id?.message}
 >
 <option value="">
 {!selectedSoId
 ? 'Select SO first'
 : soDetailLoading
 ? 'Loading items…'
 : soItems.length === 0
 ? 'No remaining quantities on this order'
 : '— Select item —'}
 </option>
 {soItems.map((item) => (
 <option key={item.id} value={item.id}>
 {item.product?.part_number
 ? `${item.product.part_number} — ${item.product.name}`
 : `Line ${item.id}`}
 {' '}({item.remaining_quantity} remaining {item.product?.unit_of_measure ?? ''})
 </option>
 ))}
 </Select>

 {/* Passed outgoing inspection select */}
 <Select
 label="Passed outgoing inspection"
 required
 {...register(`items.${index}.inspection_id`)}
 disabled={!selectedSoId || !watch(`items.${index}.sales_order_item_id`) || inspectionOptionsLoading || inspectionQuery.isError}
 error={errors.items?.[index]?.inspection_id?.message}
 >
 <option value="">
 {inspectionOptionsLoading
 ? 'Loading inspections…'
 : !watch(`items.${index}.sales_order_item_id`)
 ? 'Select item first'
 : inspectionOptions.filter((inspection) => inspection.sales_order_item_id === watch(`items.${index}.sales_order_item_id`)).length === 0
 ? 'No passed inspection available'
 : '— Select inspection —'}
 </option>
 {inspectionOptions
 .filter((inspection) => inspection.sales_order_item_id === watch(`items.${index}.sales_order_item_id`))
 .map((inspection) => (
 <option key={inspection.id} value={inspection.id}>
 {inspection.inspection_number} · {inspection.remaining_quantity} remaining
 </option>
 ))}
 </Select>

 {/* Quantity */}
 <Input
 label="Qty"
 required
 type="number"
 step="0.01"
 min="0.01"
 max={inspectionOptions.find((inspection) => inspection.id === watch(`items.${index}.inspection_id`))?.remaining_quantity}
 {...register(`items.${index}.quantity`)}
 error={errors.items?.[index]?.quantity?.message}
 className="font-mono tabular-nums"
 />

 {/* Remove button */}
 <Button
 type="button"
 variant="secondary"
 size="sm"
 iconOnly
 icon={<LuX size={14} />}
 onClick={() => remove(index)}
 disabled={fields.length === 1}
 title="Remove line"
 aria-label="Remove line"
 className="hover:text-danger-fg hover:border-danger"
 />
 </div>
 ))}
 </div>

 <LinkButton
 onClick={() =>
 append({ sales_order_item_id: '', quantity: undefined as unknown as number, inspection_id: '' })
 }
 disabled={!selectedSoId}
 icon={<LuPlus size={14} />}
 className="mt-2 text-xs"
 >
 Add delivery line
 </LinkButton>
 </fieldset>

 {/* ── Notes ── */}
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
 Notes
 </legend>
 <Textarea
 label="Notes"
 {...register('notes')}
 rows={3}
 error={errors.notes?.message}
 placeholder="Optional delivery notes, special instructions…"
 />
 </fieldset>

 </fieldset>
 {/* ── Actions ── */}
 <FormActions>
 <Button
 type="button"
 variant="secondary"
 onClick={() => navigate('/supply-chain/deliveries')}
 >
 Cancel
 </Button>
 <Button
 type="submit"
 variant="primary"
 disabled={isSubmitting || mutation.isPending || Boolean(submission) || selectedOrderQuery.isError || inspectionQuery.isError}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Creating…' : 'Create delivery'}
 </Button>
 </FormActions>
 </form>
 </div>
 );
}
