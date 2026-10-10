/** Supply Chain — Create Shipment (inbound import PO shipment). */
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { shipmentsApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const costAmount = z.string().max(16).regex(/^\d{1,13}(?:\.\d{1,2})?$/, 'Enter a non-negative amount with up to 2 decimals.').optional().or(z.literal(''));
const schema = z.object({
 purchase_order_id: z.string().min(1, 'Purchase order is required'),
 carrier: z.string().max(100).optional().or(z.literal('')),
 vessel: z.string().max(100).optional().or(z.literal('')),
 container_number: z.string().max(32).optional().or(z.literal('')),
 bl_number: z.string().max(32).optional().or(z.literal('')),
 etd: z.string().optional().or(z.literal('')),
 eta: z.string().optional().or(z.literal('')),
 notes: z.string().max(2000).optional().or(z.literal('')),
 freight_cost: costAmount,
 insurance_cost: costAmount,
 duties_amount: costAmount,
 brokerage_fee: costAmount,
 other_charges: costAmount,
});
type FormValues = z.infer<typeof schema>;

export default function CreateShipmentPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [poSearch, setPoSearch] = useState('');

 const { data: posData, isLoading: posLoading, isError: posError, refetch: refetchPurchaseOrders } = useQuery({
 queryKey: ['supply-chain', 'shipments', 'purchase-order-options', poSearch],
 queryFn: () => shipmentsApi.purchaseOrderOptions({ search: poSearch, per_page: 100 }),
 });
 const poList = posData?.data ?? [];

  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 purchase_order_id: '',
 carrier: '',
 vessel: '',
 container_number: '',
 bl_number: '',
 etd: '',
  eta: '',
 notes: '',
  freight_cost: '',
  insurance_cost: '',
  duties_amount: '',
  brokerage_fee: '',
  other_charges: '',
  },
 });
 const { register, handleSubmit, setError, watch, formState: { errors, isSubmitting } } = form;
 const selectedPo = poList.find((po) => po.id === watch('purchase_order_id'));

 const mutation = useMutation({
 mutationFn: (data: FormValues) =>
 shipmentsApi.create({
 purchase_order_id: data.purchase_order_id,
 carrier: data.carrier || undefined,
 vessel: data.vessel || undefined,
 container_number: data.container_number || undefined,
 bl_number: data.bl_number || undefined,
 etd: data.etd || undefined,
 eta: data.eta || undefined,
 notes: data.notes || undefined,
 freight_cost: data.freight_cost || undefined,
 insurance_cost: data.insurance_cost || undefined,
 duties_amount: data.duties_amount || undefined,
 brokerage_fee: data.brokerage_fee || undefined,
 other_charges: data.other_charges || undefined,
 }),
 onSuccess: (shipment) => {
 qc.invalidateQueries({ queryKey: ['supply-chain', 'shipments'] });
 toast.success('Shipment created');
 navigate(`/supply-chain/shipments/${shipment.id}`);
 },
 onError: (err) => {
 applyServerValidationErrors(err, setError, 'Failed to create shipment.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader
 title="New shipment"
 backTo="/supply-chain/shipments"
 backLabel="Shipments"
 />
      {posError && (
        <div role="alert" className="mx-5 mt-3 flex items-center justify-between gap-3 rounded-md border border-danger/30 bg-danger-bg px-3 py-2 text-sm text-danger-fg">
          <span>Failed to load purchase orders.</span>
          <Button variant="secondary" size="sm" onClick={() => void refetchPurchaseOrders()}>
            Retry purchase orders
          </Button>
        </div>
      )}
      {!posLoading && !posError && poList.length === 0 && (
        <p role="status" className="mx-5 mt-3 text-sm text-muted">
          No shipment-ready purchase orders are available. Send the order to the supplier or resolve its response first.
        </p>
      )}
      <FormDraftBanner safety={safety} />
 <form
 onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
 className="min-w-0 w-full px-5 py-4"
 >
 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Purchase order</legend>
 <Select searchable
 label="Purchase order"
 onSearch={setPoSearch}
 {...register('purchase_order_id')}
 error={errors.purchase_order_id?.message}
 required
 disabled={posLoading || posError}
 >
        <option value="">
 {posLoading ? 'Loading POs…' : posError ? 'Failed to load POs' : '— Select shipment-ready PO —'}
        </option>
 {poList.map((po) => (
 <option key={po.id} value={po.id}>
 {po.po_number} — {po.vendor?.name ?? '—'}
 </option>
 ))}
 </Select>
 </fieldset>

 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Carrier & vessel</legend>
 <div className="grid grid-cols-2 gap-3">
 <Input
 label="Carrier"
 {...register('carrier')}
 error={errors.carrier?.message}
 placeholder="Enter carrier name"
 />
 <Input
 label="Vessel"
 {...register('vessel')}
 error={errors.vessel?.message}
 placeholder="Enter vessel name"
 />
 <Input
 label="Incoterm"
 value={selectedPo?.incoterm ?? '—'}
 readOnly
 helper="Uses the purchase order term printed on customs documents."
 />
 </div>
 <div className="grid grid-cols-2 gap-3 mt-3">
 <Input
 label="Container number"
 {...register('container_number')}
 error={errors.container_number?.message}
 placeholder="Container number"
 className="font-mono"
 />
 <Input
 label="Bill of lading number"
 {...register('bl_number')}
 error={errors.bl_number?.message}
 placeholder="Bill of lading number"
 className="font-mono"
 />
 </div>
 </fieldset>

 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Schedule</legend>
 <div className="grid grid-cols-2 gap-3">
 <Input
 label="Estimated departure (ETD)"
 type="date"
 {...register('etd')}
 error={errors.etd?.message}
 />
 <Input
 label="Estimated arrival (ETA)"
 type="date"
 {...register('eta')}
 error={errors.eta?.message}
 />
 </div>
 </fieldset>

 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Landed costs</legend>
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
 <Input label="Freight cost" type="number" min="0" step="0.01" prefix="₱" {...register('freight_cost')} error={errors.freight_cost?.message} />
 <Input label="Insurance cost" type="number" min="0" step="0.01" prefix="₱" {...register('insurance_cost')} error={errors.insurance_cost?.message} />
 <Input label="Duties" type="number" min="0" step="0.01" prefix="₱" {...register('duties_amount')} error={errors.duties_amount?.message} />
 <Input label="Brokerage fee" type="number" min="0" step="0.01" prefix="₱" {...register('brokerage_fee')} error={errors.brokerage_fee?.message} />
 <Input label="Other charges" type="number" min="0" step="0.01" prefix="₱" {...register('other_charges')} error={errors.other_charges?.message} />
 </div>
 </fieldset>

 <fieldset className="mb-6">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Notes</legend>
 <Textarea
 label="Notes"
 {...register('notes')}
 rows={3}
 error={errors.notes?.message}
 placeholder="Optional shipping notes…"
 />
 </fieldset>

 <FormActions>
 <Button
 type="button"
 variant="secondary"
 onClick={() => navigate('/supply-chain/shipments')}
 >
 Cancel
 </Button>
 <Button
 type="submit"
 variant="primary"
 disabled={isSubmitting || mutation.isPending || posError || poList.length === 0}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Creating…' : 'Create shipment'}
 </Button>
 </FormActions>
 </form>
 </div>
 );
}
