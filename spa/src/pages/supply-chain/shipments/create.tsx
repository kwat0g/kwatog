/** Supply Chain — Create Shipment (inbound import PO shipment). */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { shipmentsApi } from '@/api/supply-chain';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';

const schema = z.object({
  purchase_order_id: z.string().min(1, 'Purchase order is required'),
  carrier: z.string().max(100).optional().or(z.literal('')),
  vessel: z.string().max(100).optional().or(z.literal('')),
  container_number: z.string().max(32).optional().or(z.literal('')),
  bl_number: z.string().max(32).optional().or(z.literal('')),
  etd: z.string().optional().or(z.literal('')),
  eta: z.string().optional().or(z.literal('')),
  notes: z.string().max(2000).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function CreateShipmentPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: posData } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', 'for-shipment'],
    queryFn: () => purchaseOrdersApi.list({ status: 'sent', per_page: 200 }),
  });
  const poList = posData?.data ?? [];

  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
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
    },
  });

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

  return (
    <div>
      <PageHeader
        title="New shipment"
        backTo="/supply-chain/shipments"
        backLabel="Shipments"
      />
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-3xl mx-auto px-5 py-6"
      >
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Purchase order</legend>
          <Select
            label="Purchase order"
            {...register('purchase_order_id')}
            error={errors.purchase_order_id?.message}
            required
          >
            <option value="">— Select approved PO —</option>
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
              placeholder="e.g. Evergreen"
            />
            <Input
              label="Vessel"
              {...register('vessel')}
              error={errors.vessel?.message}
              placeholder="e.g. EVER GIVEN"
            />
          </div>
          <div className="grid grid-cols-2 gap-3 mt-3">
            <Input
              label="Container number"
              {...register('container_number')}
              error={errors.container_number?.message}
              placeholder="e.g. TCKU3456789"
              className="font-mono"
            />
            <Input
              label="Bill of lading number"
              {...register('bl_number')}
              error={errors.bl_number?.message}
              placeholder="e.g. EVGL0012345"
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
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Notes</legend>
          <Textarea
            label="Notes"
            {...register('notes')}
            rows={3}
            error={errors.notes?.message}
            placeholder="Optional shipping notes…"
          />
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
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
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Creating…' : 'Create shipment'}
          </Button>
        </div>
      </form>
    </div>
  );
}
