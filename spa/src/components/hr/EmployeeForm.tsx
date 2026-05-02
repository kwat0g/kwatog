import { useEffect, useMemo } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { MaskedInput } from '@/components/ui/MaskedInput';
import { useQuery } from '@tanstack/react-query';
import { departmentsApi } from '@/api/hr/departments';
import { positionsApi } from '@/api/hr/positions';
import { digitsOnly } from '@/lib/phFormat';
import { onFormInvalid } from '@/lib/formErrors';
import type { Employee } from '@/types/hr';

// Names: letters, spaces, periods, apostrophes, hyphens. Up to 100 chars.
const namePattern = /^[\p{L}\s.'\-]+$/u;

const optString = (max: number) =>
  z.string().max(max, `Must be ${max} characters or fewer`).optional().or(z.literal(''));

// Helper: optional digits-only field. `len` is exact length OR [min, max] range.
const phDigits = (label: string, len: number | [number, number]) =>
  z.string().optional().refine(
    (v) => {
      if (!v) return true; // empty / undefined OK
      const d = digitsOnly(v).length;
      return typeof len === 'number'
        ? d === len
        : d >= len[0] && d <= len[1];
    },
    {
      message: typeof len === 'number'
        ? `${label} must be ${len} digits`
        : `${label} must be ${len[0]}-${len[1]} digits`,
    },
  );

const moneyPattern = /^\d+(\.\d{1,2})?$/;

export const employeeSchema = z.object({
  first_name: z.string().min(1, 'Required').max(100).regex(namePattern, 'Letters, spaces, ., \', - only'),
  middle_name: z.string().max(100).regex(namePattern, 'Letters, spaces, ., \', - only').optional().or(z.literal('')),
  last_name: z.string().min(1, 'Required').max(100).regex(namePattern, 'Letters, spaces, ., \', - only'),
  suffix: optString(20),
  birth_date: z.string().min(1, 'Required'),
  gender: z.enum(['male', 'female']),
  civil_status: z.enum(['single', 'married', 'widowed', 'separated', 'divorced']),
  nationality: optString(50),

  street_address: optString(200),
  barangay: optString(100),
  city: optString(100),
  province: optString(100),
  zip_code: z.string().max(10).regex(/^[0-9]{0,10}$/, 'Digits only').optional().or(z.literal('')),

  // Stored digits-only on the backend; the form holds the digits-only value too.
  mobile_number: z.string().optional().refine(
    (v) => !v || (digitsOnly(v).length === 11 && digitsOnly(v).startsWith('09')),
    { message: 'Must be 11 digits starting with 09' },
  ),
  email: z.string().email('Invalid email').max(255).optional().or(z.literal('')),
  emergency_contact_name: optString(100),
  emergency_contact_relation: optString(50),
  emergency_contact_phone: z.string().optional().refine(
    (v) => !v || (digitsOnly(v).length >= 7 && digitsOnly(v).length <= 15),
    { message: 'Phone must be 7-15 digits' },
  ),

  sss_no: phDigits('SSS', 10),
  philhealth_no: phDigits('PhilHealth', 12),
  pagibig_no: phDigits('Pag-IBIG', 12),
  tin: phDigits('TIN', [9, 12]),

  department_id: z.string().min(1, 'Required'),
  position_id: z.string().min(1, 'Required'),
  employment_type: z.enum(['regular', 'probationary', 'contractual', 'project_based']),
  pay_type: z.enum(['monthly', 'daily']),
  date_hired: z.string().min(1, 'Required'),
  date_regularized: z.string().optional().or(z.literal('')),
  basic_monthly_salary: z.string().optional().refine(
    (v) => !v || moneyPattern.test(v),
    { message: 'Numbers only, up to 2 decimals' },
  ),
  daily_rate: z.string().optional().refine(
    (v) => !v || moneyPattern.test(v),
    { message: 'Numbers only, up to 2 decimals' },
  ),

  bank_name: optString(100),
  bank_account_no: z.string().max(50).regex(/^[A-Za-z0-9\-\s]*$/, 'Letters, digits, spaces, hyphens only').optional().or(z.literal('')),
}).refine(
  (d) => d.pay_type !== 'monthly' || (!!d.basic_monthly_salary && moneyPattern.test(d.basic_monthly_salary) && Number(d.basic_monthly_salary) > 0),
  { message: 'Enter a valid monthly salary greater than 0', path: ['basic_monthly_salary'] },
).refine(
  (d) => d.pay_type !== 'daily' || (!!d.daily_rate && moneyPattern.test(d.daily_rate) && Number(d.daily_rate) > 0),
  { message: 'Enter a valid daily rate greater than 0', path: ['daily_rate'] },
).refine(
  (d) => !d.basic_monthly_salary || Number(d.basic_monthly_salary) <= 9_999_999.99,
  { message: 'Maximum 9,999,999.99', path: ['basic_monthly_salary'] },
).refine(
  (d) => !d.daily_rate || Number(d.daily_rate) <= 99_999.99,
  { message: 'Maximum 99,999.99', path: ['daily_rate'] },
);

export type EmployeeFormValues = z.infer<typeof employeeSchema>;

function defaults(employee?: Employee | null): EmployeeFormValues {
  return {
    first_name: employee?.first_name ?? '',
    middle_name: employee?.middle_name ?? '',
    last_name: employee?.last_name ?? '',
    suffix: employee?.suffix ?? '',
    birth_date: employee?.birth_date ?? '',
    gender: (employee?.gender as 'male' | 'female') ?? 'male',
    civil_status: (employee?.civil_status as EmployeeFormValues['civil_status']) ?? 'single',
    nationality: employee?.nationality ?? 'Filipino',

    street_address: employee?.address.street ?? '',
    barangay: employee?.address.barangay ?? '',
    city: employee?.address.city ?? '',
    province: employee?.address.province ?? '',
    zip_code: employee?.address.zip_code ?? '',

    mobile_number: digitsOnly(employee?.contact.mobile_number ?? ''),
    email: employee?.contact.email ?? '',
    emergency_contact_name: employee?.contact.emergency_contact_name ?? '',
    emergency_contact_relation: employee?.contact.emergency_contact_relation ?? '',
    emergency_contact_phone: digitsOnly(employee?.contact.emergency_contact_phone ?? ''),

    sss_no: digitsOnly(employee?.sss_no ?? ''),
    philhealth_no: digitsOnly(employee?.philhealth_no ?? ''),
    pagibig_no: digitsOnly(employee?.pagibig_no ?? ''),
    tin: digitsOnly(employee?.tin ?? ''),

    department_id: employee?.department?.id ?? '',
    position_id: employee?.position?.id ?? '',
    employment_type: (employee?.employment_type as EmployeeFormValues['employment_type']) ?? 'probationary',
    pay_type: (employee?.pay_type as 'monthly' | 'daily') ?? 'monthly',
    date_hired: employee?.date_hired ?? '',
    date_regularized: employee?.date_regularized ?? '',
    basic_monthly_salary: employee?.basic_monthly_salary ?? '',
    daily_rate: employee?.daily_rate ?? '',

    bank_name: employee?.bank_name ?? '',
    bank_account_no: employee?.bank_account_no ?? '',
  };
}

interface Props {
  employee?: Employee | null;
  onSubmit: (data: EmployeeFormValues) => void | Promise<void>;
  onCancel: () => void;
  isPending: boolean;
  /** RHF setError callback exposed via ref for the page to map server errors. */
  registerSetError?: (fn: (field: keyof EmployeeFormValues, msg: string) => void) => void;
  submitLabel: string;
}

export function EmployeeForm({ employee, onSubmit, onCancel, isPending, registerSetError, submitLabel }: Props) {
  const {
    register, handleSubmit, watch, setError, control,
    formState: { errors, isSubmitting },
  } = useForm<EmployeeFormValues>({
    resolver: zodResolver(employeeSchema),
    defaultValues: defaults(employee),
  });

  useEffect(() => {
    registerSetError?.((field, msg) => setError(field, { type: 'server', message: msg }));
  }, [registerSetError, setError]);

  const payType = watch('pay_type');
  const departmentId = watch('department_id');

  const { data: departments = [] } = useQuery({
    queryKey: ['hr', 'departments', 'tree'],
    queryFn: () => departmentsApi.tree(),
  });
  const { data: positionsResp } = useQuery({
    queryKey: ['hr', 'positions', 'all', departmentId],
    queryFn: () => positionsApi.list({ department_id: departmentId, per_page: 100 }),
    enabled: !!departmentId,
  });
  const positions = useMemo(() => positionsResp?.data ?? [], [positionsResp]);

  const todayStr = new Date().toISOString().slice(0, 10);
  const minBirthStr = new Date(Date.now() - 1000 * 60 * 60 * 24 * 365.25 * 15).toISOString().slice(0, 10);

  return (
    <form onSubmit={handleSubmit(onSubmit, onFormInvalid<EmployeeFormValues>())} className="max-w-4xl mx-auto px-5 py-6 space-y-8">
      <Section title="Personal information">
        <div className="grid grid-cols-2 gap-3">
          <Input label="First name" required maxLength={100} autoComplete="given-name" {...register('first_name')} error={errors.first_name?.message} />
          <Input label="Middle name" maxLength={100} {...register('middle_name')} error={errors.middle_name?.message} />
          <Input label="Last name" required maxLength={100} autoComplete="family-name" {...register('last_name')} error={errors.last_name?.message} />
          <Input label="Suffix" placeholder="Jr., Sr., III" maxLength={20} {...register('suffix')} error={errors.suffix?.message} />
          <Input label="Birth date" type="date" required max={minBirthStr} min="1900-01-01" {...register('birth_date')} error={errors.birth_date?.message} />
          <Select label="Gender" required {...register('gender')} error={errors.gender?.message}>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </Select>
          <Select label="Civil status" required {...register('civil_status')} error={errors.civil_status?.message}>
            <option value="single">Single</option>
            <option value="married">Married</option>
            <option value="widowed">Widowed</option>
            <option value="separated">Separated</option>
            <option value="divorced">Divorced</option>
          </Select>
          <Input label="Nationality" maxLength={50} {...register('nationality')} error={errors.nationality?.message} />
        </div>
      </Section>

      <Section title="Address">
        <div className="grid grid-cols-2 gap-3">
          <Input label="Street address" maxLength={200} autoComplete="street-address" {...register('street_address')} error={errors.street_address?.message} />
          <Input label="Barangay" maxLength={100} {...register('barangay')} error={errors.barangay?.message} />
          <Input label="City" maxLength={100} autoComplete="address-level2" {...register('city')} error={errors.city?.message} />
          <Input label="Province" maxLength={100} autoComplete="address-level1" {...register('province')} error={errors.province?.message} />
          <Input label="ZIP code" className="font-mono" inputMode="numeric" maxLength={10} placeholder="1234" autoComplete="postal-code" {...register('zip_code')} error={errors.zip_code?.message} />
        </div>
      </Section>

      <Section title="Contact">
        <div className="grid grid-cols-2 gap-3">
          <Controller
            name="mobile_number"
            control={control}
            render={({ field }) => (
              <MaskedInput label="Mobile number" kind="mobile" autoComplete="tel"
                value={field.value} onChange={(raw) => field.onChange(raw)}
                error={errors.mobile_number?.message} />
            )}
          />
          <Input label="Email" type="email" autoComplete="email" maxLength={255} {...register('email')} error={errors.email?.message} />
          <Input label="Emergency contact name" maxLength={100} {...register('emergency_contact_name')} error={errors.emergency_contact_name?.message} />
          <Input label="Relation" maxLength={50} {...register('emergency_contact_relation')} error={errors.emergency_contact_relation?.message} />
          <Controller
            name="emergency_contact_phone"
            control={control}
            render={({ field }) => (
              <MaskedInput label="Emergency phone" kind="mobile" autoComplete="tel"
                value={field.value} onChange={(raw) => field.onChange(raw)}
                helper="7-15 digits"
                error={errors.emergency_contact_phone?.message} />
            )}
          />
        </div>
      </Section>

      <Section title="Employment">
        <div className="grid grid-cols-2 gap-3">
          <Select label="Department" required {...register('department_id')} error={errors.department_id?.message}>
            <option value="">— Select —</option>
            {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </Select>
          <Select label="Position" required {...register('position_id')} error={errors.position_id?.message} disabled={!departmentId}>
            <option value="">{departmentId ? '— Select —' : '— Pick department first —'}</option>
            {positions.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
          </Select>
          <Select label="Employment type" required {...register('employment_type')} error={errors.employment_type?.message}>
            <option value="probationary">Probationary</option>
            <option value="regular">Regular</option>
            <option value="contractual">Contractual</option>
            <option value="project_based">Project-based</option>
          </Select>
          <Select label="Pay type" required {...register('pay_type')} error={errors.pay_type?.message}>
            <option value="monthly">Monthly</option>
            <option value="daily">Daily</option>
          </Select>
          <Input label="Date hired" type="date" required max={todayStr} min="1980-01-01" {...register('date_hired')} error={errors.date_hired?.message} />
          <Input label="Date regularized" type="date" max={todayStr} {...register('date_regularized')} error={errors.date_regularized?.message} />
          {payType === 'monthly' && (
            <Input
              label="Monthly salary"
              type="number"
              step="0.01"
              min="0"
              max="9999999.99"
              prefix="₱"
              className="font-mono tabular-nums text-right"
              placeholder="0.00"
              required
              {...register('basic_monthly_salary')}
              error={errors.basic_monthly_salary?.message}
            />
          )}
          {payType === 'daily' && (
            <Input
              label="Daily rate"
              type="number"
              step="0.01"
              min="0"
              max="99999.99"
              prefix="₱"
              className="font-mono tabular-nums text-right"
              placeholder="0.00"
              required
              {...register('daily_rate')}
              error={errors.daily_rate?.message}
            />
          )}
        </div>
      </Section>

      <Section title="Government IDs" hint="Stored encrypted at rest. Digits-only — formatting is automatic.">
        <div className="grid grid-cols-2 gap-3">
          <Controller name="sss_no" control={control}
            render={({ field }) => (
              <MaskedInput label="SSS number" kind="sss"
                value={field.value} onChange={(raw) => field.onChange(raw)}
                error={errors.sss_no?.message} />
            )} />
          <Controller name="philhealth_no" control={control}
            render={({ field }) => (
              <MaskedInput label="PhilHealth" kind="philhealth"
                value={field.value} onChange={(raw) => field.onChange(raw)}
                error={errors.philhealth_no?.message} />
            )} />
          <Controller name="pagibig_no" control={control}
            render={({ field }) => (
              <MaskedInput label="Pag-IBIG" kind="pagibig"
                value={field.value} onChange={(raw) => field.onChange(raw)}
                error={errors.pagibig_no?.message} />
            )} />
          <Controller name="tin" control={control}
            render={({ field }) => (
              <MaskedInput label="TIN" kind="tin"
                value={field.value} onChange={(raw) => field.onChange(raw)}
                error={errors.tin?.message} />
            )} />
        </div>
      </Section>

      <Section title="Banking">
        <div className="grid grid-cols-2 gap-3">
          <Input label="Bank name" maxLength={100} {...register('bank_name')} error={errors.bank_name?.message} />
          <Input label="Account number" className="font-mono" maxLength={50} placeholder="1234-5678-9012"
            {...register('bank_account_no')} error={errors.bank_account_no?.message} />
        </div>
      </Section>

      <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
        <Button type="button" variant="secondary" onClick={onCancel} disabled={isSubmitting || isPending}>Cancel</Button>
        <Button type="submit" variant="primary" disabled={isSubmitting || isPending} loading={isPending}>
          {isPending ? 'Saving…' : submitLabel}
        </Button>
      </div>
    </form>
  );
}

function Section({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
  return (
    <fieldset>
      <legend className="text-2xs uppercase tracking-wider text-muted font-medium mb-3 flex items-center gap-2">
        <span>{title}</span>
        {hint && <span className="lowercase text-text-subtle font-normal">· {hint}</span>}
      </legend>
      {children}
    </fieldset>
  );
}
