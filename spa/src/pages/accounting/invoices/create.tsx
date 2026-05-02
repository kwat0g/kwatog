import { useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Plus, X } from 'lucide-react';
import { customersApi } from '@/api/accounting/customers';
import { accountsApi } from '@/api/accounting/accounts';
import { invoicesApi } from '@/api/accounting/invoices';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import { UNIT_OPTIONS } from '@/lib/units';
import type { ApiValidationError } from '@/types';

const itemSchema = z.object({
  revenue_account_id: z.string().min(1, 'Required'),
  description:        z.string().min(1, 'Required').max(200),
  quantity:           z.coerce.number().positive('> 0'),
  unit:               z.string().max(20).optional().or(z.literal('')),
  unit_price:         z.coerce.number().min(0, '≥ 0'),
});

const schema = z.object({
  customer_id: z.string().min(1, 'Customer is required'),
  date:        z.string().min(1, 'Date is required'),
  due_date:    z.string().optional().or(z.literal('')),
  is_vatable:  z.boolean().default(true),
  remarks:     z.string().max(1000).optional().or(z.literal('')),
  items:       z.array(itemSchema).min(1, 'At least one item'),
});
type FormValues = z.infer<typeof schema>;

export default function CreateInvoicePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [params] = useSearchParams();
  const presetCustomer = params.get('customer_id') ?? '';

  const { data: customersResp } = useQuery({
    queryKey: ['accounting', 'customers', 'all'],
    queryFn: () => customersApi.list({ per_page: 200, is_active: true }),
  });
  const { data: accountsResp } = useQuery({
    queryKey: ['accounting', 'accounts', 'revenue'],
    queryFn: () => accountsApi.list({ per_page: 200, type: 'revenue', is_active: true }),
  });
  const customers = customersResp?.data ?? [];
  const accounts = accountsResp?.data ?? [];

  const { register, control, handleSubmit, watch, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      customer_id: presetCustomer, date: new Date().toISOString().slice(0, 10),
      due_date: '', is_vatable: true, remarks: '',
      items: [{ revenue_account_id: '', description: '', quantity: 1, unit: '', unit_price: 0 }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const items = watch('items');
  const isVatable = watch('is_vatable');

  const totals = useMemo(() => {
    let subtotal = 0;
    for (const it of items) subtotal += (Number(it.quantity) || 0) * (Number(it.unit_price) || 0);
    const vat = isVatable ? subtotal * 0.12 : 0;
    return { subtotal: subtotal.toFixed(2), vat: vat.toFixed(2), total: (subtotal + vat).toFixed(2) };
  }, [items, isVatable]);

  const mutation = useMutation({
    mutationFn: (d: FormValues) => invoicesApi.create({
      customer_id: d.customer_id, date: d.date, due_date: d.due_date || undefined,
      is_vatable: d.is_vatable, remarks: d.remarks || undefined,
      items: d.items.map((it) => ({
        revenue_account_id: it.revenue_account_id,
        description:        it.description,
        quantity:           String(it.quantity),
        unit:               it.unit || undefined,
        unit_price:         String(it.unit_price),
      })),
    }),
    onSuccess: (inv) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
      toast.success('Draft invoice saved.');
      navigate(`/accounting/invoices/${inv.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) => setError(f as keyof FormValues, { type: 'server', message: (msgs as string[])[0] }));
      } else toast.error(e.response?.data?.message ?? 'Failed to save invoice.');
    },
  });

  return (
    <div>
      <PageHeader title="New invoice" backTo="/accounting/invoices" backLabel="Invoices" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Header">
          <div className="grid grid-cols-3 gap-3">
            <Select label="Customer" required {...register('customer_id')} error={errors.customer_id?.message}>
              <option value="">— Select customer —</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
            <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
            <Input label="Due date" type="date" {...register('due_date')} error={errors.due_date?.message} />
            <div className="flex items-end col-span-2"><Switch label="VAT-able (12%)" {...register('is_vatable')} /></div>
            <div />
            <Textarea label="Remarks" rows={2} className="col-span-3" {...register('remarks')} error={errors.remarks?.message} />
          </div>
          <p className="text-xs text-muted mt-2">Note: invoices are saved as drafts. Finalize from the detail page to assign a number and post the JE.</p>
        </Panel>

        <Panel title="Line items">
          <div className="border border-default rounded-md overflow-hidden">
            <div className="grid grid-cols-12 gap-2 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
              <div className="col-span-3">Description</div>
              <div className="col-span-3">Revenue account</div>
              <div className="col-span-1 text-right">Qty</div>
              <div className="col-span-1">Unit</div>
              <div className="col-span-2 text-right">Unit price</div>
              <div className="col-span-1 text-right">Total</div>
              <div className="col-span-1" />
            </div>
            {fields.map((field, idx) => {
              const it = items[idx] ?? ({} as FormValues['items'][number]);
              const lineTotal = ((Number(it.quantity) || 0) * (Number(it.unit_price) || 0)).toFixed(2);
              return (
                <div key={field.id} className="grid grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start">
                  <div className="col-span-3"><Input {...register(`items.${idx}.description` as const)} /></div>
                  <div className="col-span-3">
                    <Select {...register(`items.${idx}.revenue_account_id` as const)}>
                      <option value="">— Account —</option>
                      {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
                    </Select>
                  </div>
                  <div className="col-span-1"><Input type="number" step="0.01" min="0.01" className="font-mono tabular-nums text-right" {...numberInputProps()} {...register(`items.${idx}.quantity` as const)} /></div>
                  <div className="col-span-1">
                    <Select {...register(`items.${idx}.unit` as const)}>
                      <option value="">—</option>
                      {UNIT_OPTIONS.map((u) => <option key={u.value} value={u.value}>{u.value}</option>)}
                    </Select>
                  </div>
                  <div className="col-span-2"><Input type="number" step="0.01" min="0" className="font-mono tabular-nums text-right" {...numberInputProps()} {...register(`items.${idx}.unit_price` as const)} /></div>
                  <div className="col-span-1 pt-1.5 text-right font-mono tabular-nums text-sm">{formatPeso(lineTotal)}</div>
                  <div className="col-span-1 flex justify-end pt-1.5">
                    {fields.length > 1 && (
                      <button type="button" className="text-muted hover:text-danger-fg" onClick={() => remove(idx)}><X size={14} /></button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
          <div className="flex items-center justify-between mt-3">
            <Button type="button" variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => append({ revenue_account_id: '', description: '', quantity: 1, unit: '', unit_price: 0 })}>
              Add line
            </Button>
            <div className="text-sm font-mono tabular-nums">
              <div className="text-muted">Subtotal: <span className="text-primary">{formatPeso(totals.subtotal)}</span></div>
              <div className="text-muted">VAT: <span className="text-primary">{formatPeso(totals.vat)}</span></div>
              <div className="text-base font-medium">Total: {formatPeso(totals.total)}</div>
            </div>
          </div>
        </Panel>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/invoices')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>Save draft</Button>
        </div>
      </form>
    </div>
  );
}
