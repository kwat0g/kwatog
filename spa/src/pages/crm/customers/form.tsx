import { useFormContext } from 'react-hook-form';
import type { ReactNode } from 'react';
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
import {
 customerAddressField,
 customerAgreementDateField,
 customerContactNameField,
 customerCreditLimitField,
 customerEmailField,
 customerLegalNameField,
 customerPaymentTermsField,
 customerPhoneField,
 customerRoleTitleField,
 customerWarrantyDaysField,
} from '@/lib/validation/customerFields';
import { CustomerDeliveryLocationPicker } from '@/components/accounting/CustomerDeliveryLocationPicker';

// eslint-disable-next-line react-refresh/only-export-components
export const customerSchema = z.object({
 name: customerLegalNameField,
 contact_person: customerContactNameField,
 contact_person_role: customerRoleTitleField,
 email: customerEmailField,
 phone: customerPhoneField,
 address: customerAddressField,
 // Chain 1 — mirrors StoreCustomerRequest/UpdateCustomerRequest.
 delivery_address: customerAddressField,
 delivery_contact_person: customerContactNameField,
 delivery_contact_phone: customerPhoneField,
  delivery_latitude: z.preprocess((value) => value === '' || value == null ? undefined : value, z.coerce.number().min(-90).max(90).optional()),
  delivery_longitude: z.preprocess((value) => value === '' || value == null ? undefined : value, z.coerce.number().min(-180).max(180).optional()),
 credit_limit: customerCreditLimitField,
 payment_terms_days: z.preprocess(
  (value) => value === '' || value == null ? undefined : value,
  customerPaymentTermsField.optional(),
 ),
 allowed_payment_methods: z.array(z.enum(['cash', 'check', 'bank_transfer'])).nullable().optional(),
 shipping_payer: z.enum(['customer', 'company']).nullable().optional().or(z.literal('')),
 agreement_starts_on: customerAgreementDateField,
 agreement_ends_on: customerAgreementDateField,
 warranty_duration_days: customerWarrantyDaysField,
 warranty_report_window_days: customerWarrantyDaysField,
 warranty_start_basis: z.enum(['delivery', 'invoice']).nullable().optional().or(z.literal('')),
 warranty_covered_issues: z.array(z.enum(['manufacturing_defect', 'shipping_damage', 'customer_caused_damage'])).nullable().optional(),
 warranty_remedies: z.array(z.enum(['repair', 'replace', 'credit', 'refund'])).nullable().optional(),
 is_active: z.boolean().default(true),
}).refine((value) => !value.agreement_starts_on || !value.agreement_ends_on || value.agreement_ends_on >= value.agreement_starts_on, {
 message: 'Agreement end date must be on or after its start date.', path: ['agreement_ends_on'],
}).refine((value) => (value.delivery_latitude == null) === (value.delivery_longitude == null), {
 message: 'Choose a complete delivery location pin.', path: ['delivery_latitude'],
});

export type CustomerFormValues = z.infer<typeof customerSchema>;
export type CustomerFormInput = z.input<typeof customerSchema>;
// eslint-disable-next-line react-refresh/only-export-components
export const customerResolver = zodResolver(customerSchema) as Resolver<z.input<typeof customerSchema>, unknown, CustomerFormValues>;
// New customers must have a pinned receiving location; legacy customer edits can retain missing coordinates.
// eslint-disable-next-line react-refresh/only-export-components
export const customerCreateResolver = zodResolver(customerSchema.superRefine((value, context) => {
 if (!value.contact_person?.trim()) {
  context.addIssue({ code: 'custom', path: ['contact_person'], message: 'Enter the contact person’s name.' });
 }
 if (!value.contact_person_role?.trim()) {
  context.addIssue({ code: 'custom', path: ['contact_person_role'], message: 'Enter the contact person’s role or title.' });
 }
 if (!value.email?.trim() && !value.phone?.trim()) {
  context.addIssue({ code: 'custom', path: ['phone'], message: 'Provide an email address or phone number.' });
 }
 if (!value.address?.trim()) {
  context.addIssue({ code: 'custom', path: ['address'], message: 'Enter the billing address.' });
 }
 if (value.payment_terms_days == null) {
  context.addIssue({ code: 'custom', path: ['payment_terms_days'], message: 'Enter the default payment terms.' });
 }
 if (!value.allowed_payment_methods?.length) {
  context.addIssue({ code: 'custom', path: ['allowed_payment_methods'], message: 'Select at least one allowed payment method.' });
 }
 if (!value.delivery_address?.trim()) {
  context.addIssue({ code: 'custom', path: ['delivery_address'], message: 'Enter the delivery address.' });
 }
 if (value.delivery_latitude == null || value.delivery_longitude == null) {
  context.addIssue({ code: 'custom', path: ['delivery_latitude'], message: 'Select the delivery location on the map.' });
 }
})) as Resolver<z.input<typeof customerSchema>, unknown, CustomerFormValues>;

export type CustomerFormSection = 'all' | 'customer' | 'billing' | 'delivery' | 'agreement';

export function CustomerForm({
 section = 'all',
 showFallbackWarranty = true,
 customerSetupFooter,
}: {
 section?: CustomerFormSection;
 showFallbackWarranty?: boolean;
 customerSetupFooter?: ReactNode;
}) {
 const { register, formState: { errors } } = useFormContext<CustomerFormValues>();
 const isCustomerSetupStep = section === 'customer';
 const isBillingSetupStep = section === 'billing';
 const isDeliverySetupStep = section === 'delivery';

 const customerFields = (
  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
   <div className="sm:col-span-2">
    <Input label="Customer name" required {...register('name')} error={errors.name?.message as string} placeholder="Enter customer name" />
   </div>
   <Input label="Contact person" required={isCustomerSetupStep} {...register('contact_person')} error={errors.contact_person?.message as string} placeholder="Enter contact name" />
   <Input label="Contact role / title" required={isCustomerSetupStep} {...register('contact_person_role')} error={errors.contact_person_role?.message as string} placeholder="Sales officer, manager, CEO" />
   {isCustomerSetupStep && <p className="sm:col-span-2 -mb-2 text-xs text-muted"><span className="font-medium text-primary">Contact method required:</span> provide an email address or phone number. Each field is optional when the other is provided.</p>}
   <Input label="Phone" helper="Optional if an email address is provided." {...register('phone')} error={errors.phone?.message as string} placeholder="Enter phone number" />
   <Input label="Email" type="email" helper="Optional if a phone number is provided." {...register('email')} error={errors.email?.message as string} />
  </div>
 );

 const billingAddress = (
  <Textarea label="Billing address" required={isBillingSetupStep} helper="Used on invoices." rows={2} {...register('address')} error={errors.address?.message as string} placeholder="Enter customer address" />
 );

 const commercialTerms = (
  <div className="space-y-4">
   <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
    <Input label="Default payment terms (days)" required={isBillingSetupStep} min={0} max={365} className="font-mono tabular-nums text-right" helper="Orders may override this value." {...numberInputProps({ decimal: false })} {...register('payment_terms_days')} error={errors.payment_terms_days?.message as string} />
    <Input label="Credit limit (optional)" min="0" prefix="₱" className="font-mono tabular-nums text-right" helper="Reference only; does not block orders." {...numberInputProps()} step="0.01" {...register('credit_limit')} error={errors.credit_limit?.message as string} />
   </div>
   <fieldset aria-describedby={`allowed-payment-methods-help${errors.allowed_payment_methods?.message ? ' allowed-payment-methods-error' : ''}`} aria-invalid={Boolean(errors.allowed_payment_methods)}>
    <legend className="mb-1 text-label text-muted font-medium">Allowed payment methods{isBillingSetupStep && <><span aria-hidden="true" className="text-danger-fg"> *</span><span className="sr-only"> required</span></>}</legend>
    <div className="grid grid-cols-1 gap-x-2 gap-y-1 sm:grid-cols-3">{[['cash', 'Cash'], ['check', 'Check'], ['bank_transfer', 'Bank transfer']].map(([value, label]) => <Checkbox key={value} className="w-full justify-start rounded-md px-2 text-left hover:bg-subtle" label={label} value={value} aria-invalid={Boolean(errors.allowed_payment_methods)} aria-describedby={errors.allowed_payment_methods?.message ? 'allowed-payment-methods-error' : 'allowed-payment-methods-help'} {...register('allowed_payment_methods')} />)}</div>
    <p id="allowed-payment-methods-help" className="mt-1 text-xs text-muted">{isBillingSetupStep ? 'Choose every method the customer may use for payment.' : 'Leave empty to allow all payment methods.'}</p>
    {errors.allowed_payment_methods?.message && <p id="allowed-payment-methods-error" role="alert" className="mt-1 text-xs text-danger-fg">{errors.allowed_payment_methods.message as string}</p>}
   </fieldset>
  </div>
 );

 const deliveryFields = (
  <>
   <p className="mb-3 text-xs text-muted">Enter the receiving address and pin its exact location.</p>
   <Textarea label="Delivery address" required={isDeliverySetupStep} rows={3} {...register('delivery_address')} error={errors.delivery_address?.message as string} placeholder="Plant or warehouse gate, street, barangay, city" />
   <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
    <Input label="Receiving contact (optional)" {...register('delivery_contact_person')} error={errors.delivery_contact_person?.message as string} placeholder="Who signs at the gate" />
    <Input label="Contact phone (optional)" {...register('delivery_contact_phone')} error={errors.delivery_contact_phone?.message as string} placeholder="049-500-1234" className="font-mono tabular-nums" />
   </div>
   <CustomerDeliveryLocationPicker required={isDeliverySetupStep} />
  </>
 );

 const agreementFields = (
  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
   <Input label="Agreement starts (optional)" type="date" {...register('agreement_starts_on')} error={errors.agreement_starts_on?.message} />
   <Input label="Agreement ends (optional)" type="date" {...register('agreement_ends_on')} error={errors.agreement_ends_on?.message} />
   <Select label="Shipping paid by (optional)" {...register('shipping_payer')}><option value="">Not specified</option><option value="customer">Customer</option><option value="company">Company</option></Select>
  </div>
 );

 const fallbackWarranty = showFallbackWarranty && (
  <details className="border-t border-default pt-3">
   <summary className="cursor-pointer text-sm font-medium text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">Fallback warranty settings (optional)</summary>
   <section className="mt-3 space-y-3" aria-labelledby="customer-warranty-heading">
    <div><h4 id="customer-warranty-heading" className="text-label font-semibold text-primary">Fallback warranty</h4><p className="mt-0.5 text-xs text-muted">Used only when a product has no customer-specific choice.</p></div>
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
     <Input label="Warranty duration (days)" type="number" min={0} max={3650} error={errors.warranty_duration_days?.message as string} {...register('warranty_duration_days')} />
     <Input label="Report within (days)" type="number" min={0} max={3650} helper="Measured from delivery." error={errors.warranty_report_window_days?.message as string} {...register('warranty_report_window_days')} />
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
  </details>
 );

 if (section !== 'all') {
  return (
   <div className="w-full space-y-4 py-4">
    {section === 'customer' && (
     <Panel title="Customer details" className="min-w-0">
      {customerFields}
      <div className="mt-4"><Switch label="Active customer" {...register('is_active')} /></div>
      {customerSetupFooter && <div className="mt-5 border-t border-subtle pt-4">{customerSetupFooter}</div>}
     </Panel>
    )}
    {section === 'billing' && (
     <Panel title="Invoice and payment terms" className="min-w-0">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
       {billingAddress}
       {commercialTerms}
      </div>
     </Panel>
    )}
    {section === 'delivery' && <Panel title="Receiving location" className="min-w-0">{deliveryFields}</Panel>}
    {section === 'agreement' && (
     <Panel title="Agreement dates and shipping" className="min-w-0">
      <div className="space-y-4">
       <section className="space-y-3" aria-labelledby="customer-agreement-heading">
        <h3 id="customer-agreement-heading" className="text-label font-semibold text-primary">Dates and shipping</h3>
        {agreementFields}
       </section>
       {fallbackWarranty}
      </div>
     </Panel>
    )}
   </div>
  );
 }

 return (
  <div className="min-w-0 w-full py-4">
   <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
    <Panel title="Customer and billing" className="min-w-0 xl:col-span-7">
     {customerFields}
     <div className="mt-3">{billingAddress}</div>
    </Panel>

    <Panel title="Commercial terms" className="min-w-0 xl:col-span-5">
     <div className="space-y-4">
      {commercialTerms}
      <Switch label="Active customer" {...register('is_active')} />
     </div>
    </Panel>

    <Panel title="Delivery" className="min-w-0 xl:col-span-5">{deliveryFields}</Panel>

    <Panel title="Agreement" className="min-w-0 xl:col-span-7">
     <div className="space-y-4">
      <section className="space-y-3" aria-labelledby="customer-agreement-heading">
       <h4 id="customer-agreement-heading" className="text-label font-semibold text-primary">Dates and shipping</h4>
       {agreementFields}
      </section>
      {fallbackWarranty}
     </div>
    </Panel>
   </div>
  </div>
 );
}
