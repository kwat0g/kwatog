import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useQuery } from '@tanstack/react-query';
import { departmentsApi } from '@/api/hr/departments';
import { positionsApi } from '@/api/hr/positions';
import type { Employee } from '@/types/hr';

export const employeeSchema = z.object({
  first_name: z.string().min(1, 'Required').max(100),
  middle_name: z.string().max(100).optional().or(z.literal('')),
  last_name: z.string().min(1, 'Required').max(100),
  suffix: z.string().max(20).optional().or(z.literal('')),
  birth_date: z.string().min(1, 'Required'),
  gender: z.enum(['male', 'female']),
  civil_status: z.enum(['single', 'married', 'widowed', 'separated', 'divorced']),
  nationality: z.string().max(50).optional().or(z.literal('')),

  street_address: z.string().max(200).optional().or(z.literal('')),
  barangay: z.string().max(100).optional().or(z.literal('')),
  city: z.string().max(100).optional().or(z.literal('')),
  province: z.string().max(100).optional().or(z.literal('')),
  zip_code: z.string().max(10).optional().or(z.literal('')),

  mobile_number: z.string().max(20).optional().or(z.literal('')),
  email: z.string().email('Invalid email').optional().or(z.literal('')),
  emergency_contact_name: z.string().max(100).optional().or(z.literal('')),
  emergency_contact_relation: z.string().max(50).optional().or(z.literal('')),
  emergency_contact_phone: z.string().max(20).optional().or(z.literal('')),

  sss_no: z.string().max(30).optional().or(z.literal('')),
  philhealth_no: z.string().max(30).optional().or(z.literal('')),
  pagibig_no: z.string().max(30).optional().or(z.literal('')),
  tin: z.string().max(30).optional().or(z.literal('')),

  department_id: z.string().min(1, 'Required'),
  position_id: z.string().min(1, 'Required'),
  employment_type: z.enum(['regular', 'probationary', 'contractual', 'project_based']),
  pay_type: z.enum(['monthly', 'daily']),
  date_hired: z.string().min(1, 'Required'),
  date_regularized: z.string().optional().or(z.literal('')),
  basic_monthly_salary: z.string().optional().or(z.literal('')),
  daily_rate: z.string().optional().or(z.literal('')),

  bank_name: z.string().max(100).optional().or(z.literal('')),
  bank_account_no: z.string().max(50).optional().or(z.literal('')),
}).refine(
  (d) => d.pay_type !== 'monthly' || (d.basic_monthly_salary && Number(d.basic_monthly_salary) > 0),
  { message: 'Required for monthly pay type', path: ['basic_monthly_salary'] },
).refine(
  (d) => d.pay_type !== 'daily' || (d.daily_rate && Number(d.daily_rate) > 0),
  { message: 'Required for daily pay type', path: ['daily_rate'] },
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

    mobile_number: employee?.contact.mobile_number ?? '',
    email: employee?.contact.email ?? '',
    emergency_contact_name: employee?.contact.emergency_contact_name ?? '',
    emergency_contact_relation: employee?.contact.emergency_contact_relation ?? '',
    emergency_contact_phone: employee?.contact.emergency_contact_phone ?? '',

    sss_no: employee?.sss_no ?? '',
    philhealth_no: employee?.philhealth_no ?? '',
    pagibig_no: employee?.pagibig_no ?? '',
    tin: employee?.tin ?? '',

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
    register, handleSubmit, watch, setError,
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

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="max-w-4xl mx-auto px-5 py-6 space-y-8">
      <Section title="Personal information">
        <div className="grid grid-cols-2 gap-3">
          <Input label="First name" required {...register('first_name')} error={errors.first_name?.message} />
          <Input label="Middle name" {...register('middle_name')} error={errors.middle_name?.message} />
          <Input label="Last name" required {...register('last_name')} error={errors.last_name?.message} />
          <Input label="Suffix" placeholder="Jr., Sr., III" {...register('suffix')} error={errors.suffix?.message} />
          <Input label="Birth date" type="date" required {...register('birth_date')} error={errors.birth_date?.message} />
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
          <Input label="Nationality" {...register('nationality')} error={errors.nationality?.message} />
        </div>
      </Section>

      <Section title="Address">
        <div className="grid grid-cols-2 gap-3">
          <Input label="Street address" {...register('street_address')} error={errors.street_address?.message} />
          <Input label="Barangay" {...register('barangay')} error={errors.barangay?.message} />
          <Input label="City" {...register('city')} error={errors.city?.message} />
          <Input label="Province" {...register('province')} error={errors.province?.message} />
          <Input label="ZIP code" className="font-mono" {...register('zip_code')} error={errors.zip_code?.message} />
        </div>
      </Section>

      <Section title="Contact">
        <div className="grid grid-cols-2 gap-3">
          <Input label="Mobile number" className="font-mono" {...register('mobile_number')} error={errors.mobile_number?.message} />
          <Input label="Email" type="email" {...register('email')} error={errors.email?.message} />
          <Input label="Emergency contact name" {...register('emergency_contact_name')} error={errors.emergency_contact_name?.message} />
          <Input label="Relation" {...register('emergency_contact_relation')} error={errors.emergency_contact_relation?.message} />
          <Input label="Emergency phone" className="font-mono" {...register('emergency_contact_phone')} error={errors.emergency_contact_phone?.message} />
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
          <Input label="Date hired" type="date" required {...register('date_hired')} error={errors.date_hired?.message} />
          <Input label="Date regularized" type="date" {...register('date_regularized')} error={errors.date_regularized?.message} />
          {payType === 'monthly' && (
            <Input
              label="Monthly salary (₱)"
              type="number"
              step="0.01"
              className="font-mono"
              required
              {...register('basic_monthly_salary')}
              error={errors.basic_monthly_salary?.message}
            />
          )}
          {payType === 'daily' && (
            <Input
              label="Daily rate (₱)"
              type="number"
              step="0.01"
              className="font-mono"
              required
              {...register('daily_rate')}
              error={errors.daily_rate?.message}
            />
          )}
        </div>
      </Section>

      <Section title="Government IDs" hint="Stored encrypted at rest.">
        <div className="grid grid-cols-2 gap-3">
          <Input label="SSS number" className="font-mono" {...register('sss_no')} error={errors.sss_no?.message} />
          <Input label="PhilHealth" className="font-mono" {...register('philhealth_no')} error={errors.philhealth_no?.message} />
          <Input label="Pag-IBIG" className="font-mono" {...register('pagibig_no')} error={errors.pagibig_no?.message} />
          <Input label="TIN" className="font-mono" {...register('tin')} error={errors.tin?.message} />
        </div>
      </Section>

      <Section title="Banking">
        <div className="grid grid-cols-2 gap-3">
          <Input label="Bank name" {...register('bank_name')} error={errors.bank_name?.message} />
          <Input label="Account number" className="font-mono" {...register('bank_account_no')} error={errors.bank_account_no?.message} />
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
