import { useState } from 'react';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Link } from 'react-router-dom';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { useAuthStore } from '@/stores/authStore';
import type { DeliveryAttemptFields, DeliveryAttemptSourceLine, ReportDeliveryAttempt } from '@/types/deliveryAttempt';

// Quantities remain decimal strings in the payload. Integer arithmetic keeps
// the displayed residual exact even when two entered quantities are fractional.
const quantity = z.string().regex(/^\d{1,10}(\.\d{1,2})?$/, 'Enter a quantity with up to 2 decimal places.');
function hundredths(value: string): bigint {
  if (!/^\d+(\.\d{1,3})?$/.test(value)) return 0n;
  const [whole, fraction = ''] = value.split('.');
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2));
}
function decimal(value: bigint): string {
  return `${value / 100n}.${(value % 100n).toString().padStart(2, '0')}`;
}
const lineSchema = z.object({
  delivery_item_id: z.string().min(1),
  dispatched_quantity: z.string(),
  customer_received_quantity: quantity,
  customer_received_damaged_quantity: quantity,
  truck_return_quantity: quantity,
  truck_return_damaged_quantity: quantity,
}).superRefine((line, ctx) => {
  const received = hundredths(line.customer_received_quantity);
  const truck = hundredths(line.truck_return_quantity);
  if (received + truck > hundredths(line.dispatched_quantity)) {
    ctx.addIssue({ code: 'custom', path: ['truck_return_quantity'], message: 'Received plus returning cannot exceed the dispatched quantity.' });
  }
  if (hundredths(line.customer_received_damaged_quantity) > received) {
    ctx.addIssue({ code: 'custom', path: ['customer_received_damaged_quantity'], message: 'Cannot exceed the quantity received by the customer.' });
  }
  if (hundredths(line.truck_return_damaged_quantity) > truck) {
    ctx.addIssue({ code: 'custom', path: ['truck_return_damaged_quantity'], message: 'Cannot exceed the quantity returning on the truck.' });
  }
});
const schema = z.object({
  reason_code: z.string().min(1, 'Select what happened.'),
  notes: z.string().trim().max(2000),
  correction_reason: z.string().trim().max(2000),
  lines: z.array(lineSchema).min(1),
});
type Values = z.infer<typeof schema>;
const savedSchema = z.object({
  request_key: z.string().uuid(), reason_code: z.string(), notes: z.string(),
  expected_version: z.number().optional(), correction_reason: z.string().optional(),
  lines: z.array(z.object({
    delivery_item_id: z.string(), customer_received_quantity: z.string(), customer_received_damaged_quantity: z.string(),
    truck_return_quantity: z.string(), truck_return_damaged_quantity: z.string(), unaccounted_quantity: z.string(),
  })),
});
function readPending(key: string): ReportDeliveryAttempt | null {
  try {
    const parsed = savedSchema.safeParse(JSON.parse(localStorage.getItem(key) ?? 'null'));
    return parsed.success ? parsed.data : null;
  } catch { return null; }
}

interface Props extends DeliveryAttemptFields {
  deliveryId: string;
  lines: DeliveryAttemptSourceLine[];
  report: (payload: ReportDeliveryAttempt) => Promise<unknown>;
  amend?: (payload: ReportDeliveryAttempt) => Promise<unknown>;
  driver?: boolean;
  canOpenReturn?: boolean;
}

export function DeliveryAttemptPanel({ deliveryId, lines, report, amend, driver = false, canOpenReturn = false,
  can_report_attempt_outcome: canReport, attempt_outcome_reasons: reasons = [], attempt_outcome: outcome }: Props) {
  const userId = useAuthStore((state) => state.user?.id);
  const storageKey = `ogami:delivery-attempt:${userId}:${deliveryId}`;
  const [pending, setPending] = useState<ReportDeliveryAttempt | null>(() => readPending(storageKey));
  const [open, setOpen] = useState(Boolean(pending));
  const [failure, setFailure] = useState('');
  const qc = useQueryClient();
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      reason_code: pending?.reason_code ?? '', notes: pending?.notes ?? '', correction_reason: pending?.correction_reason ?? '',
      lines: lines.map((line) => ({
        delivery_item_id: line.id, dispatched_quantity: String(line.quantity),
        customer_received_quantity: '0', customer_received_damaged_quantity: '0',
        truck_return_quantity: decimal(hundredths(String(line.quantity))), truck_return_damaged_quantity: '0',
        ...pending?.lines.find((saved) => saved.delivery_item_id === line.id),
      })),
    },
  });
  const watched = useWatch({ control: form.control, name: 'lines' });
  function remember(value: ReportDeliveryAttempt | null) {
    setPending(value);
    try {
      if (value) localStorage.setItem(storageKey, JSON.stringify(value));
      else localStorage.removeItem(storageKey);
    } catch { /* Exact retry remains in memory when browser storage is unavailable. */ }
  }
  const refresh = () => Promise.all([
    qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries'] }),
    qc.invalidateQueries({ queryKey: ['driver'] }),
    qc.invalidateQueries({ queryKey: ['portal', 'customer'] }),
  ]);
  const mutation = useMutation({
    mutationFn: (payload: ReportDeliveryAttempt) => payload.expected_version && amend ? amend(payload) : report(payload),
    onSuccess: async () => {
      await refresh();
      remember(null);
      setFailure('');
      setOpen(false);
      toast.success('Delivery outcome recorded.');
    },
    onError: (error) => {
      const definitive = isAxiosError(error) && error.response && error.response.status < 500;
      if (definitive) {
        remember(null);
        const fields = error.response?.data?.errors as Record<string, string[]> | undefined;
        if (fields) Object.entries(fields).forEach(([key, messages]) => form.setError(key as FieldPath<Values>, { type: 'server', message: messages[0] }));
        setFailure(error.response?.data?.message ?? 'Review the quantities and try again.');
        void refresh();
      } else {
        setFailure('We could not confirm the save. Your original report is saved. Retry it to recover the result without recording it twice.');
      }
      toast.error('Could not confirm the delivery report.');
    },
  });
  const canAmend = Boolean(amend && outcome?.can_amend);
  function editReport() {
    form.reset({ reason_code: outcome?.reason_code ?? '', notes: outcome?.notes ?? '', correction_reason: '',
      lines: lines.map((line) => {
        const saved = outcome?.lines.find((row) => row.delivery_item_id === line.id);
        return { delivery_item_id: line.id, dispatched_quantity: String(line.quantity),
          customer_received_quantity: decimal(hundredths(saved?.customer_received_quantity ?? '0')),
          customer_received_damaged_quantity: decimal(hundredths(saved?.customer_received_damaged_quantity ?? '0')),
          truck_return_quantity: decimal(hundredths(saved?.truck_return_quantity ?? '0')),
          truck_return_damaged_quantity: decimal(hundredths(saved?.truck_return_damaged_quantity ?? '0')) };
      }) });
    setOpen(true);
  }
  function submit(values: Values) {
    if (canAmend && !values.correction_reason) {
      form.setError('correction_reason', { message: 'Explain what was incorrect in the earlier report.' });
      return;
    }
    const payload: ReportDeliveryAttempt = {
      ...(canAmend ? { expected_version: outcome?.version, correction_reason: values.correction_reason } : {}),
      request_key: crypto.randomUUID(), reason_code: values.reason_code, notes: values.notes,
      lines: values.lines.map(({ dispatched_quantity, ...line }) => ({
        ...line, unaccounted_quantity: decimal(hundredths(dispatched_quantity) - hundredths(line.customer_received_quantity) - hundredths(line.truck_return_quantity)),
      })),
    };
    remember(payload);
    setFailure('');
    mutation.mutate(payload);
  }
  if (!canReport && !outcome && !pending) return null;
  return <Panel title="Delivery outcome">
    {outcome && <div className="space-y-3">
      <p className="text-sm font-medium">{outcome.reason_label}</p>
      {outcome.notes && <p className="text-sm whitespace-pre-wrap break-words">{outcome.notes}</p>}
      <ul className="divide-y divide-default">
        {outcome.lines.map((line) => {
          const source = lines.find((item) => item.id === line.delivery_item_id);
          return <li key={line.delivery_item_id} className="py-3 space-y-1 text-sm">
            <p className="font-medium">{source?.product?.part_number ?? 'Delivery item'}</p>
            <p>Customer received <span className="font-mono tabular-nums">{line.customer_received_quantity}</span> · Returning <span className="font-mono tabular-nums">{line.truck_return_quantity}</span> · Unaccounted <span className="font-mono tabular-nums">{line.unaccounted_quantity}</span></p>
            {(Number(line.customer_received_damaged_quantity) > 0 || Number(line.truck_return_damaged_quantity) > 0) && <p className="text-warning-fg">Reported damaged: <span className="font-mono tabular-nums">{line.customer_received_damaged_quantity}</span> with customer · <span className="font-mono tabular-nums">{line.truck_return_damaged_quantity}</span> on truck.</p>}
            {line.warehouse_received_quantity != null && <p>Warehouse counted <span className="font-mono tabular-nums">{line.warehouse_received_quantity}</span> back at the depot.</p>}
          </li>;
        })}
      </ul>
      {outcome.variance_reason && <p className="text-sm whitespace-pre-wrap break-words">Warehouse reconciliation: {outcome.variance_reason}</p>}
      {outcome.return_request && (canOpenReturn && !driver
        ? <Link className="text-sm text-link underline" to={`/return-management/${outcome.return_request.id}`}>Open warehouse return {outcome.return_request.rma_number}</Link>
        : <p className="text-sm text-muted">Warehouse return: <span className="font-mono">{outcome.return_request.rma_number}</span></p>)}
    </div>}
    {outcome?.revisions && outcome.revisions.length > 0 && <details className="my-3 text-sm">
      <summary className="cursor-pointer text-link">Correction and recovery history ({outcome.revisions.length})</summary>
      <ol className="mt-2 space-y-2">{outcome.revisions.map((revision) => <li key={revision.id} className="whitespace-pre-wrap break-words"><span className="font-medium">{revision.kind === 'late_recovery' ? 'Late recovery' : 'Report corrected'}</span> · {revision.actor_name}<p>{revision.reason}</p></li>)}</ol>
    </details>}
    {outcome?.return_requests && outcome.return_requests.length > 1 && canOpenReturn && !driver && <ul className="space-y-2 text-sm my-3">{outcome.return_requests.slice(1).map((rma) => <li key={rma.id}><Link className="text-link underline" to={`/return-management/${rma.id}`}>Recovery return {rma.rma_number}</Link></li>)}</ul>}
    {!open && canAmend && <Button variant="secondary" className="mt-3" onClick={editReport}>Correct report</Button>}
    {!open && (canReport || pending) && <div className="space-y-3">
      <p className="text-sm text-muted">Use this if the customer could not receive everything, goods were damaged, or quantities are missing.</p>
      <Button variant="secondary" size={driver ? 'touch' : 'md'} onClick={() => setOpen(true)}>{pending ? 'Recover pending report' : 'Report delivery problem'}</Button>
    </div>}
    {open && (canReport || canAmend || pending) && <form onSubmit={form.handleSubmit(submit)} className="space-y-4">
      <p className="text-sm text-muted">Record what happened at this stop. Warehouse will count goods returning on the truck before putting them into quarantine.</p>
      <fieldset disabled={Boolean(pending) || mutation.isPending} className="space-y-4">
        <Select label="What happened?" className={driver ? 'text-base min-h-hit' : undefined} required {...form.register('reason_code')} error={form.formState.errors.reason_code?.message}>
          <option value="">Select a reason</option>
          {reasons.map((reason) => <option key={reason.value} value={reason.value}>{reason.label}</option>)}
          {pending && !reasons.some((reason) => reason.value === pending.reason_code) && <option value={pending.reason_code}>{pending.reason_code.replace(/_/g, ' ')}</option>}
        </Select>
        {lines.map((line, index) => {
          const errors = form.formState.errors.lines?.[index];
          const residual = hundredths(String(line.quantity)) - hundredths(watched?.[index]?.customer_received_quantity ?? '0') - hundredths(watched?.[index]?.truck_return_quantity ?? '0');
          return <fieldset key={line.id} className="border-t border-default pt-4 space-y-3">
            <legend className="text-sm font-medium">{line.product?.part_number ?? `Item ${index + 1}`} · Dispatched <span className="font-mono tabular-nums">{line.quantity}</span> {line.unit_of_measure ?? ''}</legend>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Input label="Received by customer" className={driver ? 'text-base min-h-hit' : undefined} required type="number" min="0" step="0.01" max={line.quantity} {...form.register(`lines.${index}.customer_received_quantity`)} error={errors?.customer_received_quantity?.message} />
              <Input label="Returning on truck" className={driver ? 'text-base min-h-hit' : undefined} required type="number" min="0" step="0.01" max={line.quantity} {...form.register(`lines.${index}.truck_return_quantity`)} error={errors?.truck_return_quantity?.message} />
            </div>
            <p className={residual > 0n ? 'text-sm text-warning-fg' : 'text-sm text-muted'} aria-live="polite">{residual < 0n ? 'The entered quantities exceed the dispatched quantity.' : `Unaccounted quantity: ${decimal(residual)}${residual > 0n ? '. Explain the difference in your notes.' : ''}`}</p>
            <details>
              <summary className="cursor-pointer text-sm text-link min-h-hit py-1">Record damaged quantities (if any)</summary>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                <Input label="Damaged among customer receipt" className={driver ? 'text-base min-h-hit' : undefined} type="number" min="0" step="0.01" {...form.register(`lines.${index}.customer_received_damaged_quantity`)} error={errors?.customer_received_damaged_quantity?.message} />
                <Input label="Damaged among truck returns" className={driver ? 'text-base min-h-hit' : undefined} type="number" min="0" step="0.01" {...form.register(`lines.${index}.truck_return_damaged_quantity`)} error={errors?.truck_return_damaged_quantity?.message} />
              </div>
            </details>
          </fieldset>;
        })}
        {canAmend && <Textarea label="Reason for correction" required rows={2} maxLength={2000} {...form.register('correction_reason')} error={form.formState.errors.correction_reason?.message} />}
        <Textarea label="Additional details" className={driver ? 'text-base' : undefined} rows={3} maxLength={2000} {...form.register('notes')} error={form.formState.errors.notes?.message} />
      </fieldset>
      {failure && <p role="alert" className="text-sm text-danger-fg">{failure}</p>}
      <p className="text-sm text-muted">Check the counts before saving. Corrections are kept in the delivery history.</p>
      <div className="flex flex-wrap justify-end gap-2 border-t border-default pt-3">
        <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={() => setOpen(false)}>Close</Button>
        {pending
          ? <Button type="button" variant="primary" loading={mutation.isPending} onClick={() => mutation.mutate(pending)}>Retry saved report</Button>
          : <Button type="submit" variant="primary" loading={mutation.isPending}>{canAmend ? 'Save correction' : 'Save delivery outcome'}</Button>}
      </div>
    </form>}
  </Panel>;
}
