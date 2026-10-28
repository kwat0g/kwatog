import { useFormContext } from 'react-hook-form';
import type { Resolver } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Checkbox } from '@/components/ui/Checkbox';
import { numberInputProps } from '@/lib/numberInput';

// eslint-disable-next-line react-refresh/only-export-components
export const customerSchema = z.object({
 name: z.string().min(1, 'Required').max(200),
 contact_person: z.string().max(100).optional().or(z.literal('')),
 email: z.string().email('Invalid email').optional().or(z.literal('')),
 phone: z.string().max(20).optional().or(z.literal('')),
 address: z.string().max(500).optional().or(z.literal('')),
 // Chain 1 — mirrors StoreCustomerRequest/UpdateCustomerRequest.
 delivery_address: z.string().max(500).optional().or(z.literal('')),
 delivery_contact_person: z.string().max(100).optional().or(z.literal('')),
 delivery_contact_phone: z.string().max(20).optional().or(z.literal('')),
 credit_limit: z.coerce.number().min(0).max(99999999.99).optional().or(z.literal('').transform(() => undefined)),
 payment_terms_days: z.coerce.number().int().min(0).max(365),
 allowed_payment_methods: z.array(z.enum(['cash', 'check', 'bank_transfer'])).nullable().optional(),
 shipping_payer: z.enum(['customer', 'company']).nullable().optional().or(z.literal('')),
 agreement_starts_on: z.string().optional().or(z.literal('')),
 agreement_ends_on: z.string().optional().or(z.literal('')),
 warranty_duration_days: z.preprocess((value) => value === '' ? undefined : value, z.coerce.number().int().min(0).max(3650).optional()),
 warranty_report_window_days: z.preprocess((value) => value === '' ? undefined : value, z.coerce.number().int().min(0).max(3650).optional()),
 warranty_start_basis: z.enum(['delivery', 'invoice']).nullable().optional().or(z.literal('')),
 warranty_covered_issues: z.array(z.enum(['manufacturing_defect', 'shipping_damage', 'customer_caused_damage'])).nullable().optional(),
 warranty_remedies: z.array(z.enum(['repair', 'replace', 'credit', 'refund'])).nullable().optional(),
 is_active: z.boolean().default(true),
}).refine((value) => !value.agreement_starts_on || !value.agreement_ends_on || value.agreement_ends_on >= value.agreement_starts_on, {
 message: 'Agreement end date must be on or after its start date.', path: ['agreement_ends_on'],
});

export type CustomerFormValues = z.infer<typeof customerSchema>;
// eslint-disable-next-line react-refresh/only-export-components
export const customerResolver = zodResolver(customerSchema) as Resolver<z.input<typeof customerSchema>, unknown, CustomerFormValues>;
// New customers need explicit warranty defaults; keep legacy customer edits optional.
// eslint-disable-next-line react-refresh/only-export-components
export const customerCreateResolver = zodResolver(customerSchema.superRefine((value, context) => {
 if (value.warranty_duration_days == null) context.addIssue({ code: 'custom', path: ['warranty_duration_days'], message: 'Required' });
 if (value.warranty_report_window_days == null) context.addIssue({ code: 'custom', path: ['warranty_report_window_days'], message: 'Required' });
})) as Resolver<z.input<typeof customerSchema>, unknown, CustomerFormValues>;

export function CustomerForm({ requireWarrantyDefaults = false }: { requireWarrantyDefaults?: boolean }) {
 const { register, formState: { errors } } = useFormContext<CustomerFormValues>();

 return (
 <div className="min-w-0 w-full py-4">
 <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
 <Panel title="Customer and billing" className="min-w-0 xl:col-span-7">
 <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
 <div className="sm:col-span-2">
 <Input label="Customer name" required {...register('name')} error={errors.name?.message as string} placeholder="Enter customer name" />
 </div>
 <Input label="Contact person" {...register('contact_person')} error={errors.contact_person?.message as string} placeholder="Enter contact name" />
 <Input label="Phone" {...register('phone')} error={errors.phone?.message as string} placeholder="Enter phone number" />
 <Input label="Email" type="email" {...register('email')} error={errors.email?.message as string} />
 <div className="sm:col-span-2">
 <Textarea label="Billing address" helper="Used on invoices." rows={2} {...register('address')} error={errors.address?.message as string} placeholder="Enter customer address" />
 </div>
 </div>
 </Panel>

 <Panel title="Commercial terms" className="min-w-0 xl:col-span-5">
 <div className="space-y-4">
 <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
 <Input label="Payment terms (days)" min={0} max={365} className="font-mono tabular-nums text-right" {...numberInputProps({ decimal: false })} {...register('payment_terms_days')} error={errors.payment_terms_days?.message as string} />
 <Input label="Credit limit" min="0" prefix="₱" className="font-mono tabular-nums text-right" {...numberInputProps()} step="0.01" {...register('credit_limit')} error={errors.credit_limit?.message as string} />
 </div>
 <Switch label="Active — visible when creating sales orders" {...register('is_active')} />
 <fieldset>
 <legend className="mb-1 text-label text-muted font-medium">Allowed payment methods</legend>
 <div className="grid grid-cols-1 gap-x-2 gap-y-1 sm:grid-cols-3">{[['cash', 'Cash'], ['check', 'Check'], ['bank_transfer', 'Bank transfer']].map(([value, label]) => <Checkbox key={value} className="w-full justify-start rounded-md px-2 text-left hover:bg-subtle" label={label} value={value} {...register('allowed_payment_methods')} />)}</div>
 <p className="mt-1 text-xs text-muted">Leave all unchecked to allow any supported method.</p>
 </fieldset>
 </div>
 </Panel>

 <Panel title="Delivery" className="min-w-0 xl:col-span-5">
 <p className="mb-3 text-xs text-muted">Where the truck unloads and who signs for the goods. Billing address is used on invoices; the driver is sent to this address.</p>
 <Textarea label="Delivery address" rows={3} {...register('delivery_address')} error={errors.delivery_address?.message as string} placeholder="Plant or warehouse gate, street, barangay, city" />
 <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
 <Input label="Receiving contact" {...register('delivery_contact_person')} error={errors.delivery_contact_person?.message as string} placeholder="Who signs at the gate" />
 <Input label="Contact phone" {...register('delivery_contact_phone')} error={errors.delivery_contact_phone?.message as string} placeholder="049-500-1234" className="font-mono tabular-nums" />
 </div>
 </Panel>

 <Panel title="Agreement and warranty" className="min-w-0 xl:col-span-7">
 <div className="space-y-4">
 <section className="space-y-3" aria-labelledby="customer-agreement-heading">
 <div>
 <h4 id="customer-agreement-heading" className="text-label font-semibold text-primary">Agreement dates and shipping</h4>
 <p className="mt-0.5 text-xs text-muted">Record the signed agreement's effective dates.</p>
 </div>
 <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
 <Input label="Agreement starts" type="date" {...register('agreement_starts_on')} />
 <Input label="Agreement ends" type="date" {...register('agreement_ends_on')} error={errors.agreement_ends_on?.message} />
 <Select label="Shipping paid by" {...register('shipping_payer')}><option value="">Not specified</option><option value="customer">Customer</option><option value="company">Company</option></Select>
 </div>
 </section>

 <section className="space-y-3 border-t border-default pt-4" aria-labelledby="customer-warranty-heading">
 <div>
 <h4 id="customer-warranty-heading" className="text-label font-semibold text-primary">Warranty defaults</h4>
 <p className="mt-0.5 text-xs text-muted">Apply to products without a product-specific warranty override.</p>
 </div>
 <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
 <Input label="Warranty duration (days)" type="number" min={0} max={3650} required={requireWarrantyDefaults} error={errors.warranty_duration_days?.message as string} {...register('warranty_duration_days')} />
 <Input label="Report within (days)" type="number" min={0} max={3650} helper="Measured from delivery." required={requireWarrantyDefaults} error={errors.warranty_report_window_days?.message as string} {...register('warranty_report_window_days')} />
 <Select label="Warranty starts from" {...register('warranty_start_basis')}><option value="">Not specified</option><option value="delivery">Delivery</option><option value="invoice">Invoice</option></Select>
 </div>
 <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
 <fieldset>
 <legend className="mb-1 text-label text-muted font-medium">Covered issues</legend>
 <div className="grid grid-cols-1 gap-y-1">{[['manufacturing_defect', 'Manufacturing defect'], ['shipping_damage', 'Shipping damage'], ['customer_caused_damage', 'Customer-caused damage']].map(([value, label]) => <Checkbox key={value} className="w-full justify-start rounded-md px-2 text-left hover:bg-subtle" label={label} value={value} {...register('warranty_covered_issues')} />)}</div>
 </fieldset>
 <fieldset>
 <legend className="mb-1 text-label text-muted font-medium">Warranty remedies</legend>
 <div className="grid grid-cols-1 gap-y-1">{[['repair', 'Repair'], ['replace', 'Replace'], ['credit', 'Credit'], ['refund', 'Refund']].map(([value, label]) => <Checkbox key={value} className="w-full justify-start rounded-md px-2 text-left hover:bg-subtle" label={label} value={value} {...register('warranty_remedies')} />)}</div>
 </fieldset>
 </div>
 </section>
 </div>
 </Panel>
 </div>
 </div>
 );
}
