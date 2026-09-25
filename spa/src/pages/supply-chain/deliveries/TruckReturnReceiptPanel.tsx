import { useState } from 'react';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { warehouseApi } from '@/api/inventory/warehouse';
import { deliveriesApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { useAuthStore } from '@/stores/authStore';
import type { DeliveryAttemptOutcome, DeliveryAttemptSourceLine, ReceiveTruckReturn } from '@/types/deliveryAttempt';

const schema = z.object({
  request_key: z.string().uuid(),
  quarantine_location_id: z.string(),
  variance_reason: z.string().trim().max(2000),
  lines: z.array(z.object({
    delivery_item_id: z.string().min(1),
    received_quantity: z.string().regex(/^\d{1,10}(\.\d{1,3})?$/, 'Enter a quantity with up to 3 decimal places.'),
  })).min(1),
}).superRefine((value, ctx) => {
  if (value.lines.some((line) => Number(line.received_quantity) > 0) && !value.quarantine_location_id) {
    ctx.addIssue({ code: 'custom', path: ['quarantine_location_id'], message: 'Choose the quarantine location for returned goods.' });
  }
});
type Values = z.infer<typeof schema>;
function loadPending(key: string): Values | null {
  try {
    const saved = schema.safeParse(JSON.parse(localStorage.getItem(key) ?? 'null'));
    return saved.success ? saved.data : null;
  } catch { return null; }
}

export function TruckReturnReceiptPanel({ deliveryId, lines, outcome, canReceive, late = false }: {
  deliveryId: string;
  lines: DeliveryAttemptSourceLine[];
  outcome: DeliveryAttemptOutcome;
  canReceive: boolean;
  late?: boolean;
}) {
  const userId = useAuthStore((state) => state.user?.id);
  const storageKey = `ogami:truck-return-receipt:${late ? 'late' : 'first'}:${userId}:${deliveryId}`;
  const [pending, setPending] = useState<Values | null>(() => loadPending(storageKey));
  const [failure, setFailure] = useState('');
  const [open, setOpen] = useState(Boolean(pending));
  const qc = useQueryClient();
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: pending ?? {
      request_key: crypto.randomUUID(), quarantine_location_id: '', variance_reason: '',
      lines: outcome.lines.map((line) => ({ delivery_item_id: line.delivery_item_id, received_quantity: late ? '0' : line.truck_return_quantity })),
    },
  });
  const quantities = useWatch({ control: form.control, name: 'lines' });
  const hasPhysicalReturn = quantities?.some((line) => Number(line.received_quantity) > 0);
  const locationsQuery = useQuery({
    queryKey: ['warehouse-tree', 'truck-returns'], queryFn: () => warehouseApi.tree(), enabled: canReceive && open,
  });
  const locations = (locationsQuery.data ?? []).filter((warehouse) => warehouse.is_active).flatMap((warehouse) =>
    (warehouse.zones ?? []).filter((zone) => zone.zone_type === 'quarantine').flatMap((zone) =>
      (zone.locations ?? []).filter((location) => location.is_active && !location.is_blocked).map((location) => ({
        id: location.id, label: `${warehouse.code} / ${zone.code} / ${location.code}`,
      })),
    ),
  );
  function remember(value: Values | null) {
    setPending(value);
    try {
      if (value) localStorage.setItem(storageKey, JSON.stringify(value));
      else localStorage.removeItem(storageKey);
    } catch { /* The open page still keeps the original submission for retry. */ }
  }
  const mutation = useMutation({
    mutationFn: (values: Values) => {
      const hasGoods = values.lines.some((line) => Number(line.received_quantity) > 0);
      const payload: ReceiveTruckReturn = { ...values, quarantine_location_id: hasGoods ? values.quarantine_location_id || null : null };
      return late ? deliveriesApi.receiveLateReturn(deliveryId, payload) : deliveriesApi.receiveTruckReturn(deliveryId, payload);
    },
    onSuccess: async () => {
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries'] }), qc.invalidateQueries({ queryKey: ['driver'] }),
        qc.invalidateQueries({ queryKey: ['inventory'] }), qc.invalidateQueries({ queryKey: ['return-request'] }),
      ]);
      remember(null); setOpen(false); setFailure('');
      form.reset({ request_key: crypto.randomUUID(), quarantine_location_id: '', variance_reason: '', lines: outcome.lines.map((line) => ({ delivery_item_id: line.delivery_item_id, received_quantity: '0' })) });
      toast.success(late ? 'Recovered goods received into quarantine.' : 'Truck return reconciled.');
    },
    onError: (error) => {
      if (isAxiosError(error) && error.response && error.response.status < 500) {
        remember(null);
        const fields = error.response.data?.errors as Record<string, string[]> | undefined;
        if (fields) Object.entries(fields).forEach(([key, messages]) => form.setError(key as FieldPath<Values>, { type: 'server', message: messages[0] }));
        setFailure(error.response.data?.message ?? 'Check the physical counts and try again.');
        void qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries', deliveryId] });
      } else {
        setFailure('We could not confirm this receipt. Retry the saved count to recover it without receiving stock twice.');
      }
      toast.error('Could not confirm the truck return.');
    },
  });
  if (!canReceive && !pending) return null;
  return <Panel title={late ? "Late-found goods" : "Warehouse truck return"}>
    {!open ? <div className="space-y-3">
      <p className="text-sm">{late ? 'Record goods found after the first depot count. Each recovery gets its own quarantine and Quality review.' : 'Count all goods arriving back from this delivery. Only the actual count enters quarantine; missing goods remain recorded as a variance.'}</p>
      <Button variant="secondary" onClick={() => setOpen(true)}>{pending ? 'Recover pending receipt' : late ? 'Receive late-found goods' : 'Record depot count'}</Button>
    </div> : <form onSubmit={form.handleSubmit((values) => { remember(values); setFailure(''); mutation.mutate(values); })} className="space-y-4">
      <p className="text-sm text-muted">{late ? 'Enter only the additional goods received now. Earlier counts and Quality decisions remain in the history.' : 'This is the first depot count for the trip. Returned stock stays unavailable until Quality inspection and disposition are complete.'}</p>
      <fieldset disabled={Boolean(pending) || mutation.isPending} className="space-y-4">
        {outcome.lines.map((line, index) => {
          const source = lines.find((item) => item.id === line.delivery_item_id);
          return <Input key={line.delivery_item_id} label={`Physically received — ${source?.product?.part_number ?? `item ${index + 1}`}`}
            helper={late ? `${line.unaccounted_quantity} still unaccounted. Enter only the goods found now.` : `Driver reported ${line.truck_return_quantity} returning and ${line.unaccounted_quantity} unaccounted.`}
            type="number" min="0" step="0.001" required {...form.register(`lines.${index}.received_quantity`)} error={form.formState.errors.lines?.[index]?.received_quantity?.message} />;
        })}
        {locationsQuery.isError && <div role="alert" className="text-sm text-danger-fg">Could not load quarantine locations. <Button type="button" onClick={() => locationsQuery.refetch()}>Retry locations</Button></div>}
        <Select label="Quarantine location" required={hasPhysicalReturn} {...form.register('quarantine_location_id')}
          disabled={locationsQuery.isFetching || locationsQuery.isError} error={form.formState.errors.quarantine_location_id?.message}>
          <option value="">{locationsQuery.isFetching ? 'Loading locations…' : 'Choose a quarantine location'}</option>
          {locations.map((location) => <option key={location.id} value={location.id}>{location.label}</option>)}
        </Select>
        {!locationsQuery.isFetching && !locationsQuery.isError && !locations.length && hasPhysicalReturn && <p className="text-sm text-warning-fg">No active quarantine location is available. Ask Warehouse to configure one before receiving these goods.</p>}
        <Textarea label={late ? "Where were the goods found?" : "Count differences or missing goods"} required={late} helper={late ? "Include the location, date and circumstances of recovery." : "Explain any difference from the driver's report, including goods that have not returned."}
          rows={3} maxLength={2000} {...form.register('variance_reason')} error={form.formState.errors.variance_reason?.message} />
      </fieldset>
      {failure && <p role="alert" className="text-sm text-danger-fg">{failure}</p>}
      <div className="flex flex-wrap justify-end gap-2 border-t border-default pt-3">
        <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={() => setOpen(false)}>Close</Button>
        {pending
          ? <Button type="button" variant="primary" loading={mutation.isPending} onClick={() => mutation.mutate(pending)}>Retry saved receipt</Button>
          : <Button type="submit" variant="primary" loading={mutation.isPending} disabled={hasPhysicalReturn && (locationsQuery.isFetching || locationsQuery.isError || !locations.length)}>{late ? 'Receive recovered goods' : 'Confirm depot count'}</Button>}
      </div>
    </form>}
  </Panel>;
}
