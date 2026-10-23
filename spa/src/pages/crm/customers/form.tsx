import { useFormContext } from 'react-hook-form';
import { z } from 'zod';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
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
 is_active: z.boolean().default(true),
});

export type CustomerFormValues = z.infer<typeof customerSchema>;

export function CustomerForm() {
 const { register, formState: { errors } } = useFormContext<CustomerFormValues>();

 return (
 <div className="min-w-0 w-full px-5 py-4 space-y-4">
 <Panel title="Identity">
 <div className="grid grid-cols-2 gap-3">
 <div className="col-span-2">
 <Input
 label="Customer name"
 required
 {...register('name')}
 error={errors.name?.message as string}
 placeholder="Enter customer name"
 />
 </div>
 <Input
 label="Contact person"
 {...register('contact_person')}
 error={errors.contact_person?.message as string}
 placeholder="Enter contact name"
 />
 <Input
 label="Phone"
 {...register('phone')}
 error={errors.phone?.message as string}
 placeholder="Enter phone number"
 />
 <Input
 label="Email"
 type="email"
 {...register('email')}
 error={errors.email?.message as string}
 />
 <Input
 label="Payment terms (days)"
 
 min={0}
 max={365}
 className="font-mono tabular-nums text-right"
 {...numberInputProps({ decimal: false })}
 {...register('payment_terms_days')}
 error={errors.payment_terms_days?.message as string}
 />
 <div className="col-span-2">
 <Textarea
 label="Address"
 rows={2}
 {...register('address')}
 error={errors.address?.message as string}
 placeholder="Enter customer address"
 />
 </div>
 <Input
 label="Credit limit"
 
 min="0"
 prefix="₱"
 className="font-mono tabular-nums text-right"
 {...numberInputProps()}
 step="0.01"
 {...register('credit_limit')}
 error={errors.credit_limit?.message as string}
 />
 </div>
 <div className="mt-3">
 <Switch label="Active — visible when creating sales orders" {...register('is_active')} />
 </div>
 </Panel>

 <Panel title="Delivery">
 <p className="mb-3 text-xs text-muted">
 Where the truck unloads and who signs for the goods. The address above is the billing
 address used on invoices; the driver is sent to this one.
 </p>
 <Textarea
 label="Delivery address"
 rows={3}
 {...register('delivery_address')}
 error={errors.delivery_address?.message as string}
 placeholder="Plant or warehouse gate, street, barangay, city"
 />
 <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
 <Input
 label="Receiving contact"
 {...register('delivery_contact_person')}
 error={errors.delivery_contact_person?.message as string}
 placeholder="Who signs at the gate"
 />
 <Input
 label="Contact phone"
 {...register('delivery_contact_phone')}
 error={errors.delivery_contact_phone?.message as string}
 placeholder="049-500-1234"
 className="font-mono tabular-nums"
 />
 </div>
 </Panel>
 </div>
 );
}
