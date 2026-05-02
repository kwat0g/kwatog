import { useEffect, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Plus, X } from 'lucide-react';
import { vendorsApi } from '@/api/accounting/vendors';
import { accountsApi } from '@/api/accounting/accounts';
import { billsApi } from '@/api/accounting/bills';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const itemSchema = z.object({
  expense_account_id: z.string().min(1, 'Required'),
  description:        z.string().min(1, 'Required').max(200),
  quantity:           z.coerce.number().positive('> 0'),
  unit:               z.string().max(20).optional().or(z.literal('')),
  unit_price:         z.coerce.number().min(0, '≥ 0'),
});

const schema = z.object({
  bill_number: z.string().min(1).max(50),
  vendor_id:   z.string().min(1, 'Vendor is required'),
  date:        z.string().min(1, 'Date is required'),
  due_date:    z.string().optional().or(z.literal('')),
  is_vatable:  z.boolean().default(true),
  remarks:     z.string().max(1000).optional().or(z.literal('')),
  items:       z.array(itemSchema).min(1, 'At least one item'),
});
type FormValues = z.infer<typeof schema>;

export default function CreateBillPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [params] = useSearchParams();
  const presetVendor = params.get('vendor_id') ?? '';

  const { data: vendorsResp } = useQuery({
    queryKey: ['accounting', 'vendors', 'all'],
    queryFn: () => vendorsApi.list({ per_page: 200, is_active: true }),
  });
  const { data: accountsResp } = useQuery({
    queryKey: ['accounting', 'accounts', 'expense'],
    queryFn: () => accountsApi.list({ per_page: 200, type: 'expense', is_active: true }),
  });
  const vendors = vendorsResp?.data ?? [];
  const accounts = accountsResp?.data ?? [];

  const { register, control, handleSubmit, watch, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      bill_number: '', vendor_id: presetVendor, date: new Date().toISOString().slice(0, 10),
      due_date: '', is_vatable: true, remarks: '',
      items: [{ expense_account_id: '', description: '', quantity: 1, unit: '', unit_price: 0 }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const items = watch('items');
  const isVatable = watch('is_vatable');

  // Auto-fill due_date when vendor changes (use payment_terms_days).
  const vendorId = watch('vendor_id');
  const date = watch('date');
  useEffect(() => {
    const v = vendors.find((x) => x.id === vendorId);
    if (v && date) {
      const d = new Date(date); d.setDate(d.getDate() + v.payment_terms_days);
      const iso = d.toISOString().slice(0, 10);
      // Don't overwrite a manually edited due_date.
      const cur = (watch('due_date') ?? '');
      if (!cur) (document.querySelector('input[name="due_date"]') as HTMLInputElement | null)?.setAttribute('value', iso);
    }
  }, [vendorId, date, vendors, watch]);

  const totals = useMemo(() => {
    let subtotal = 0;
    for (const it of items) subtotal += (Number(it.quantity) || 0) * (Number(it.unit_price) || 0);
    const vat = isVatable ? subtotal * 0.12 : 0;
    return { subtotal: subtotal.toFixed(2), vat: vat.toFixed(2), total: (subtotal + vat).toFixed(2) };
  }, [items, isVatable]);

  const mutation = useMutation({
    mutationFn: (d: FormValues) => billsApi.create({
      bill_number: d.bill_number,
      vendor_id:   d.vendor_id,
      date:        d.date,
      due_date:    d.due_date || undefined,
      is_vatable:  d.is_vatable,
      remarks:     d.remarks || undefined,
      items: d.items.map((it) => ({
        expense_account_id: it.expense_account_id,
        description:        it.description,
        quantity:           String(it.quantity),
        unit:               it.unit || undefined,
        unit_price:         String(it.unit_price),
      })),
    }),
    onSuccess: (b) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
      toast.success(`Bill ${b.bill_number} recorded.`);
      navigate(`/accounting/bills/${b.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) => setError(f as keyof FormValues, { type: 'server', message: (msgs as string[])[0] }));
      } else toast.error(e.response?.data?.message ?? 'Failed to create bill.');
    },
  });

  return (
    <div>
      <PageHeader title="New bill" backTo="/accounting/bills" backLabel="Bills" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Header">
          <div className="grid grid-cols-3 gap-3">
            <Select label="Vendor" required {...register('vendor_id')} error={errors.vendor_id?.message}>
              <option value="">— Select vendor —</option>
              {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </Select>
            <Input label="Vendor bill no." required {...register('bill_number')} error={errors.bill_number?.message} />
            <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
            <Input label="Due date" type="date" {...register('due_date')} error={errors.due_date?.message} />
            <div className="flex items-end col-span-2">
              <Switch label="VAT-able (12%)" {...register('is_vatable')} />
            </div>
            <Textarea label="Remarks" rows={2} className="col-span-3" {...register('remarks')} error={errors.remarks?.message} />
          </div>
        </Panel>

        <Panel title="Line items">
          <div className="border border-default rounded-md overflow-hidden">
            <div className="grid grid-cols-12 gap-2 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
              <div className="col-span-3">Description</div>
              <div className="col-span-3">Expense account</div>
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
                    <Select {...register(`items.${idx}.expense_account_id` as const)}>
                      <option value="">— Account —</option>
                      {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
                    </Select>
                  </div>
                  <div className="col-span-1"><Input type="number" step="0.01" min="0.01" className="font-mono tabular-nums text-right" {...register(`items.${idx}.quantity` as const)} /></div>
                  <div className="col-span-1"><Input {...register(`items.${idx}.unit` as const)} placeholder="kg" /></div>
                  <div className="col-span-2"><Input type="number" step="0.01" min="0" className="font-mono tabular-nums text-right" {...register(`items.${idx}.unit_price` as const)} /></div>
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
            <Button type="button" variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => append({ expense_account_id: '', description: '', quantity: 1, unit: '', unit_price: 0 })}>
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
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/bills')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>
            Create bill
          </Button>
        </div>
      </form>
    </div>
  );
}
