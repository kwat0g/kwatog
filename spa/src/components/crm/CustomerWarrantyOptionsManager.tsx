import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import { crmCustomersApi, type CustomerWarrantyOption } from '@/api/crm/customers';
import { productsApi } from '@/api/crm/products';
import { usePermission } from '@/hooks/usePermission';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';

const ISSUE_OPTIONS = [
  ['manufacturing_defect', 'Manufacturing defect'],
  ['shipping_damage', 'Shipping damage'],
  ['customer_caused_damage', 'Customer-caused damage'],
] as const;
const REMEDY_OPTIONS = [
  ['repair', 'Repair'],
  ['replace', 'Replace'],
  ['credit', 'Credit'],
  ['refund', 'Refund'],
] as const;

export function CustomerWarrantyOptionsManager({ customerId }: { customerId: string }) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [productId, setProductId] = useState('');
  const [label, setLabel] = useState('');
  const [duration, setDuration] = useState('');
  const [reportWindow, setReportWindow] = useState('');
  const [startBasis, setStartBasis] = useState<'delivery' | 'invoice'>('delivery');
  const [issues, setIssues] = useState<Array<(typeof ISSUE_OPTIONS)[number][0]>>(['manufacturing_defect']);
  const [remedies, setRemedies] = useState<Array<(typeof REMEDY_OPTIONS)[number][0]>>(['repair']);
  const [additionalTerms, setAdditionalTerms] = useState('');

  const products = useQuery({
    queryKey: ['crm', 'products', 'options', 'warranty-setup'],
    queryFn: () => productsApi.options({ per_page: 100, is_active: true }),
    enabled: can('accounting.customers.manage'),
  });
  const options = useQuery({
    queryKey: ['crm', 'customers', customerId, 'warranty-options'],
    queryFn: () => crmCustomersApi.warrantyOptions(customerId),
  });
  const sortedProducts = useMemo(() => [...(products.data?.data ?? [])].sort((a, b) => a.part_number.localeCompare(b.part_number)), [products.data?.data]);

  const create = useMutation({
    mutationFn: () => crmCustomersApi.createWarrantyOption(customerId, productId, {
      label: label.trim(),
      warranty_duration_days: duration === '' ? null : Number(duration),
      warranty_report_window_days: reportWindow === '' ? null : Number(reportWindow),
      warranty_start_basis: startBasis,
      warranty_covered_issues: issues,
      warranty_remedies: remedies,
      additional_terms: additionalTerms.trim() || null,
    }),
    onSuccess: (created) => {
      queryClient.setQueryData<CustomerWarrantyOption[]>(['crm', 'customers', customerId, 'warranty-options'], (current) => [...(current ?? []), created]);
      void queryClient.invalidateQueries({ queryKey: ['crm', 'customers', customerId, 'warranty-options'] });
      setLabel(''); setDuration(''); setReportWindow(''); setAdditionalTerms('');
      toast.success('Warranty choice added to the customer agreement.');
    },
    onError: () => toast.error('Could not save this warranty choice.'),
  });
  const remove = useMutation({
    mutationFn: ({ product, option }: { product: string; option: string }) => crmCustomersApi.deleteWarrantyOption(customerId, product, option),
    onSuccess: (_result, variables) => {
      queryClient.setQueryData<CustomerWarrantyOption[]>(['crm', 'customers', customerId, 'warranty-options'], (current) =>
        (current ?? []).filter((option) => option.id !== variables.option));
      void queryClient.invalidateQueries({ queryKey: ['crm', 'customers', customerId, 'warranty-options'] });
      toast.success('Warranty choice removed. Existing orders keep their saved terms.');
    },
    onError: () => toast.error('Could not remove this warranty choice.'),
  });

  const toggle = <T extends string>(values: T[], value: T, checked: boolean, set: (next: T[]) => void) => set(
    checked ? [...new Set([...values, value])] : values.filter((current) => current !== value),
  );
  const canSave = can('accounting.customers.manage') && productId !== '' && label.trim() !== ''
    && (duration === '' || (/^\d+$/.test(duration) && Number(duration) <= 3650))
    && (reportWindow === '' || (/^\d+$/.test(reportWindow) && Number(reportWindow) <= 3650));

  return (
    <section aria-labelledby="customer-warranty-options-heading" className="space-y-3 border-t border-default pt-4">
      <div>
        <h4 id="customer-warranty-options-heading" className="text-label font-semibold text-primary">Product warranty choices</h4>
        <p className="mt-1 text-xs leading-relaxed text-muted">Add the warranty options this customer may select for each product. The chosen terms are copied onto the order.</p>
      </div>
      {options.isError && <p role="alert" className="text-sm text-danger-fg">Could not load warranty choices.</p>}
      {options.data && options.data.length > 0 ? (
        <ul aria-label="Configured warranty choices" className="space-y-2">
          {options.data.map((option) => (
            <li key={option.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default bg-canvas px-3 py-2">
              <div className="min-w-0">
                <p className="font-medium">{option.product.name} <span className="font-mono text-xs text-muted">{option.product.part_number}</span></p>
                <p className="text-xs text-muted">{option.label} · {option.warranty_duration_days == null ? 'Term to be agreed' : `${option.warranty_duration_days} days`} · starts from {option.warranty_start_basis ?? 'agreement'}</p>
                {option.additional_terms && <p className="mt-1 text-xs text-muted">Other terms: {option.additional_terms}</p>}
              </div>
              {can('accounting.customers.manage') && <Button type="button" variant="ghost" size="sm" aria-label={`Remove ${option.label} for ${option.product.name}`}
                disabled={remove.isPending} icon={<LuTrash2 size={15} />} onClick={() => remove.mutate({ product: option.product.id, option: option.id })}>Remove</Button>}
            </li>
          ))}
        </ul>
      ) : options.isLoading ? <p role="status" className="text-sm text-muted">Loading warranty choices…</p> : (
        <p className="rounded-md border border-dashed border-default px-3 py-3 text-sm text-muted">No product-specific warranty choices are configured yet.</p>
      )}
      {can('accounting.customers.manage') && (
        <form aria-label="Add product warranty choice" className="space-y-3 rounded-md border border-default bg-surface p-3" onSubmit={(event) => { event.preventDefault(); if (canSave) create.mutate(); }}>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Product" required value={productId} onChange={(event) => setProductId(event.target.value)}>
              <option value="">Choose a product</option>
              {sortedProducts.map((product) => <option key={product.id} value={product.id}>{product.part_number} · {product.name}</option>)}
            </Select>
            <Input label="Warranty option name" required maxLength={120} value={label} onChange={(event) => setLabel(event.target.value)} placeholder="e.g. 1-year standard warranty" />
            <Input label="Warranty duration (days)" type="number" min={0} max={3650} step={1} value={duration} onChange={(event) => setDuration(event.target.value)} helper="Leave blank when the agreement sets the duration later." />
            <Input label="Report window (days)" type="number" min={0} max={3650} step={1} value={reportWindow} onChange={(event) => setReportWindow(event.target.value)} />
            <Select label="Warranty starts from" value={startBasis} onChange={(event) => setStartBasis(event.target.value as typeof startBasis)}>
              <option value="delivery">Delivery</option><option value="invoice">Invoice</option>
            </Select>
          </div>
          <fieldset className="space-y-2">
            <legend className="text-xs font-medium text-muted">Covered issues</legend>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              {ISSUE_OPTIONS.map(([value, title]) => <Checkbox key={value} checked={issues.includes(value)} onChange={(event) => toggle(issues, value, event.target.checked, setIssues)} label={title} />)}
            </div>
          </fieldset>
          <fieldset className="space-y-2">
            <legend className="text-xs font-medium text-muted">Remedies</legend>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              {REMEDY_OPTIONS.map(([value, title]) => <Checkbox key={value} checked={remedies.includes(value)} onChange={(event) => toggle(remedies, value, event.target.checked, setRemedies)} label={title} />)}
            </div>
          </fieldset>
          <Textarea label="Other agreement terms (optional)" maxLength={2000} rows={2} value={additionalTerms} onChange={(event) => setAdditionalTerms(event.target.value)} placeholder="Add any product-specific warranty terms not listed above." />
          <Button type="submit" disabled={!canSave || create.isPending} icon={<LuPlus size={15} />}>{create.isPending ? 'Adding…' : 'Add warranty choice'}</Button>
        </form>
      )}
    </section>
  );
}
