import { useFormContext } from 'react-hook-form';
import { z } from 'zod';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { numberInputProps } from '@/lib/numberInput';

// eslint-disable-next-line react-refresh/only-export-components
export const customerSchema = z.object({
  name:               z.string().min(1, 'Required').max(200),
  code:               z.string().min(1, 'Required').max(50),
  contact_person:     z.string().max(100).optional().or(z.literal('')),
  email:              z.string().email('Invalid email').optional().or(z.literal('')),
  phone:              z.string().max(20).optional().or(z.literal('')),
  address:            z.string().max(500).optional().or(z.literal('')),
  credit_limit:       z.coerce.number().min(0).max(99999999.99).optional().or(z.literal('').transform(() => undefined)),
  payment_terms_days: z.coerce.number().int().min(0).max(365).default(30),
  is_active:          z.boolean().default(true),
});

export type CustomerFormValues = z.infer<typeof customerSchema>;

export function CustomerForm() {
  const { register, formState: { errors } } = useFormContext<CustomerFormValues>();

  return (
    <div className="max-w-3xl mx-auto px-5 py-6 space-y-4">
      <Panel title="Identity">
        <div className="grid grid-cols-2 gap-3">
          <div className="col-span-2">
            <Input
              label="Customer name"
              required
              {...register('name')}
              error={errors.name?.message as string}
              placeholder="Toyota Motor Philippines"
            />
          </div>
          <Input
            label="Customer code"
            required
            {...register('code')}
            error={errors.code?.message as string}
            placeholder="TMP-001"
            className="font-mono"
          />
          <Input
            label="Contact person"
            {...register('contact_person')}
            error={errors.contact_person?.message as string}
            placeholder="Tanaka Hiroshi"
          />
          <Input
            label="Phone"
            {...register('phone')}
            error={errors.phone?.message as string}
            placeholder="+63 2 8888 0000"
          />
          <Input
            label="Email"
            type="email"
            {...register('email')}
            error={errors.email?.message as string}
            placeholder="purchasing@example.com"
          />
          <Input
            label="Payment terms (days)"
            type="number"
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
              placeholder="Macapagal Blvd, Pasay City, Metro Manila"
            />
          </div>
          <Input
            label="Credit limit"
            type="number"
            step="0.01"
            min="0"
            prefix="₱"
            className="font-mono tabular-nums text-right"
            {...numberInputProps()}
            {...register('credit_limit')}
            error={errors.credit_limit?.message as string}
          />
        </div>
        <div className="mt-3">
          <Switch label="Active — visible when creating sales orders" {...register('is_active')} />
        </div>
      </Panel>
    </div>
  );
}
