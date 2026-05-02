import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Pencil, UserMinus } from 'lucide-react';
import { employeesApi, type SeparateData } from '@/api/hr/employees';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { ApiValidationError } from '@/types';

const TABS = ['Overview', 'Employment history', 'Documents', 'Property'] as const;
type Tab = typeof TABS[number];

export default function EmployeeDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const qc = useQueryClient();
  const [tab, setTab] = useState<Tab>('Overview');
  const [separateOpen, setSeparateOpen] = useState(false);

  const { data: employee, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'employee', id],
    queryFn: () => employeesApi.show(id),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !employee) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Employee not found"
        description="The record may have been deleted or you don't have access."
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  return (
    <div>
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            {employee.full_name}
            <Chip variant={chipVariantForStatus(employee.status)}>
              {employee.status.replace('_', ' ')}
            </Chip>
          </span>
        }
        subtitle={<span className="font-mono">{employee.employee_no} · {employee.position?.title} · {employee.department?.name}</span>}
        backTo="/hr/employees"
        backLabel="Employees"
        actions={
          <>
            {can('hr.employees.edit') && (
              <Button variant="secondary" size="sm" icon={<Pencil size={12} />} onClick={() => navigate(`/hr/employees/${id}/edit`)}>
                Edit
              </Button>
            )}
            {can('hr.employees.separate') && employee.status === 'active' && (
              <Button variant="danger" size="sm" icon={<UserMinus size={12} />} onClick={() => setSeparateOpen(true)}>
                Separate
              </Button>
            )}
          </>
        }
      />

      {/* Tabs */}
      <div className="border-b border-default px-5 flex gap-4">
        {TABS.map((t) => (
          <button
            key={t}
            type="button"
            className={
              'h-10 text-sm border-b-2 -mb-px transition-colors ' +
              (tab === t ? 'border-accent text-primary font-medium' : 'border-transparent text-muted hover:text-primary')
            }
            onClick={() => setTab(t)}
          >
            {t}
          </button>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
        <div>
          {tab === 'Overview' && <OverviewTab employee={employee} />}
          {tab === 'Employment history' && <EmploymentHistoryTab employee={employee} />}
          {tab === 'Documents' && <DocumentsTab employee={employee} />}
          {tab === 'Property' && <PropertyTab employee={employee} />}
        </div>

        <div className="space-y-4">
          <Panel title="At a glance">
            <dl className="text-sm space-y-2">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Employee no</dt>
                <dd className="font-mono">{employee.employee_no}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Hired</dt>
                <dd className="font-mono">{formatDate(employee.date_hired)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Pay type</dt>
                <dd>{employee.pay_type === 'monthly' ? 'Monthly' : 'Daily'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">
                  {employee.pay_type === 'monthly' ? 'Monthly salary' : 'Daily rate'}
                </dt>
                <dd className="font-mono tabular-nums">
                  {formatPeso(employee.pay_type === 'monthly' ? employee.basic_monthly_salary : employee.daily_rate)}
                </dd>
              </div>
              {employee.user && (
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Linked user</dt>
                  <dd>{employee.user.email}</dd>
                </div>
              )}
            </dl>
          </Panel>
        </div>
      </div>

      {separateOpen && (
        <SeparateModal
          employeeId={id}
          fullName={employee.full_name}
          onClose={() => setSeparateOpen(false)}
          onSeparated={() => {
            qc.invalidateQueries({ queryKey: ['hr', 'employee', id] });
            qc.invalidateQueries({ queryKey: ['hr', 'employees'] });
            setSeparateOpen(false);
          }}
        />
      )}
    </div>
  );
}

function OverviewTab({ employee }: { employee: any }) {
  return (
    <div className="space-y-4">
      <Panel title="Personal">
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <Item label="Full name" value={employee.full_name} />
          <Item label="Birth date" value={formatDate(employee.birth_date)} mono />
          <Item label="Gender" value={cap(employee.gender)} />
          <Item label="Civil status" value={cap(employee.civil_status)} />
          <Item label="Nationality" value={employee.nationality} />
        </dl>
      </Panel>
      <Panel title="Contact">
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <Item label="Mobile" value={employee.contact.mobile_number} mono />
          <Item label="Email" value={employee.contact.email} />
          <Item label="Address" value={[employee.address.street, employee.address.barangay, employee.address.city, employee.address.province, employee.address.zip_code].filter(Boolean).join(', ')} />
          <Item label="Emergency contact" value={
            employee.contact.emergency_contact_name
              ? `${employee.contact.emergency_contact_name} (${employee.contact.emergency_contact_relation ?? 'n/a'}) — ${employee.contact.emergency_contact_phone ?? ''}`
              : null
          } />
        </dl>
      </Panel>
      <Panel title="Government IDs" meta="May be masked based on permissions.">
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <Item label="SSS" value={employee.sss_no} mono />
          <Item label="PhilHealth" value={employee.philhealth_no} mono />
          <Item label="Pag-IBIG" value={employee.pagibig_no} mono />
          <Item label="TIN" value={employee.tin} mono />
        </dl>
      </Panel>
      <Panel title="Banking">
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <Item label="Bank" value={employee.bank_name} />
          <Item label="Account number" value={employee.bank_account_no} mono />
        </dl>
      </Panel>
    </div>
  );
}

function EmploymentHistoryTab({ employee }: { employee: any }) {
  const items = (employee.employment_history ?? []) as any[];
  if (items.length === 0) {
    return <EmptyState icon="inbox" title="No history yet" description="Employment changes appear here over time." />;
  }
  return (
    <Panel title={`Employment history (${items.length})`} noPadding>
      <ul className="divide-y divide-subtle">
        {items.map((h) => (
          <li key={h.id} className="px-4 py-3 text-sm">
            <div className="flex items-center justify-between">
              <span className="font-medium">{cap(h.change_type.replace('_', ' '))}</span>
              <span className="font-mono text-xs text-muted">{formatDate(h.effective_date)}</span>
            </div>
            {h.remarks && <p className="text-xs text-muted mt-1">{h.remarks}</p>}
            <pre className="text-xs text-muted mt-1 overflow-x-auto">{JSON.stringify(h.to_value, null, 2)}</pre>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function DocumentsTab({ employee }: { employee: any }) {
  const docs = (employee.documents ?? []) as any[];
  if (docs.length === 0) return <EmptyState icon="file-question" title="No documents" />;
  return (
    <Panel title={`Documents (${docs.length})`} noPadding>
      <table className="w-full text-sm">
        <thead className="bg-subtle text-2xs uppercase tracking-wider text-muted">
          <tr>
            <th className="h-8 px-4 text-left">Type</th>
            <th className="h-8 px-4 text-left">File</th>
            <th className="h-8 px-4 text-left">Uploaded</th>
          </tr>
        </thead>
        <tbody>
          {docs.map((d) => (
            <tr key={d.id} className="h-8 border-b border-subtle hover:bg-subtle">
              <td className="px-4">{d.document_type}</td>
              <td className="px-4 font-mono">{d.file_name}</td>
              <td className="px-4 font-mono">{formatDateTime(d.uploaded_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function PropertyTab({ employee }: { employee: any }) {
  const items = (employee.property ?? []) as any[];
  if (items.length === 0) return <EmptyState icon="inbox" title="No property issued" />;
  return (
    <Panel title={`Issued property (${items.length})`} noPadding>
      <table className="w-full text-sm">
        <thead className="bg-subtle text-2xs uppercase tracking-wider text-muted">
          <tr>
            <th className="h-8 px-4 text-left">Item</th>
            <th className="h-8 px-4 text-right">Qty</th>
            <th className="h-8 px-4 text-left">Issued</th>
            <th className="h-8 px-4 text-left">Returned</th>
            <th className="h-8 px-4 text-left">Status</th>
          </tr>
        </thead>
        <tbody>
          {items.map((p) => (
            <tr key={p.id} className="h-8 border-b border-subtle hover:bg-subtle">
              <td className="px-4">{p.item_name}</td>
              <td className="px-4 text-right font-mono tabular-nums">{p.quantity}</td>
              <td className="px-4 font-mono">{formatDate(p.date_issued)}</td>
              <td className="px-4 font-mono">{p.date_returned ? formatDate(p.date_returned) : '—'}</td>
              <td className="px-4"><Chip variant={chipVariantForStatus(p.status)}>{p.status}</Chip></td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function Item({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div>
      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</dt>
      <dd className={mono ? 'font-mono tabular-nums' : ''}>{value || <span className="text-text-subtle">—</span>}</dd>
    </div>
  );
}

function cap(s?: string | null): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

const separateSchema = z.object({
  separation_reason: z.enum(['resigned', 'terminated', 'retired', 'end_of_contract']),
  separation_date: z.string().min(1, 'Required'),
  remarks: z.string().max(2000).optional().or(z.literal('')),
});

type SeparateFormValues = z.infer<typeof separateSchema>;

function SeparateModal({
  employeeId, fullName, onClose, onSeparated,
}: {
  employeeId: string;
  fullName: string;
  onClose: () => void;
  onSeparated: () => void;
}) {
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<SeparateFormValues>({
    resolver: zodResolver(separateSchema),
    defaultValues: { separation_reason: 'resigned', separation_date: new Date().toISOString().slice(0, 10) },
  });

  const mutation = useMutation({
    mutationFn: (d: SeparateFormValues) => employeesApi.separate(employeeId, d as SeparateData),
    onSuccess: () => {
      toast.success('Employee separated.');
      onSeparated();
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof SeparateFormValues, { type: 'server', message: msgs[0] }),
        );
      } else toast.error('Failed to separate employee.');
    },
  });

  return (
    <Modal isOpen onClose={onClose} title="Separate employee">
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-3 py-2">
        <p className="text-sm text-muted">
          Marking <span className="font-medium text-primary">{fullName}</span> as separated. This is recorded in their employment history.
        </p>
        <Select label="Reason" required {...register('separation_reason')} error={errors.separation_reason?.message}>
          <option value="resigned">Resigned</option>
          <option value="terminated">Terminated</option>
          <option value="retired">Retired</option>
          <option value="end_of_contract">End of contract</option>
        </Select>
        <Input label="Effective date" type="date" required {...register('separation_date')} error={errors.separation_date?.message} />
        <Textarea label="Remarks" {...register('remarks')} error={errors.remarks?.message} rows={3} />
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting || mutation.isPending}>Cancel</Button>
          <Button type="submit" variant="danger" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Separating…' : 'Separate'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
