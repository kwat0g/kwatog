import { cn } from '@/lib/cn';
/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState, useRef, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { LuPencil, LuUserMinus, LuEye, LuEyeOff, LuPlus, LuCheck, LuCamera } from '@/lib/icons';
import { employeesApi, type SeparateData } from '@/api/hr/employees';
import { shiftsApi } from '@/api/attendance/shifts';
import { employeeDocumentApi } from '@/api/hr/employee-documents';
import { employeeTrainingsApi } from '@/api/hr/employee-trainings';
import { employeeSkillsApi } from '@/api/hr/employee-skills';
import { trainingsApi } from '@/api/hr/trainings';
import { skillsApi } from '@/api/hr/skills';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatTime } from '@/lib/formatDate';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import type { EmployeeDocument } from '@/types/hr';
import { Panel } from '@/components/ui/Panel';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail, SkeletonPanel } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { OnboardingStepper } from '@/components/hr/OnboardingStepper';
import { SystemAccountSection } from '@/components/hr/SystemAccountSection';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { formatMobile, maskByKind } from '@/lib/phFormat';
import type { ApiValidationError } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { Tabs } from '@/components/ui/Tabs';

const TABS = [
  'Overview',
  'Employment history',
  'Attendance',
  'Leaves',
  'Loans',
  'Documents',
  'Trainings',
  'Skills',
  'Payroll',
  'Activity',
] as const;
type Tab = (typeof TABS)[number];

export default function EmployeeDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const qc = useQueryClient();
  const [tab, setTab] = useState<Tab>('Overview');
  const [separateOpen, setSeparateOpen] = useState(false);

  const {
    data: employee,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['hr', 'employee', id],
    queryFn: () => employeesApi.show(id),
  });

  const { data: currentShift } = useQuery({
    queryKey: ['attendance', 'current-shift', id],
    queryFn: () => shiftsApi.currentForEmployee(id),
    enabled: !!id,
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !employee) {
    return (
      <EmptyState
        icon="file-question"
        title="Employee not found"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
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
              {employee.status_label ?? employee.status}
            </Chip>
          </span>
        }
        subtitle={
          <span className="font-mono">
            {employee.employee_no} · {employee.position?.title} · {employee.department?.name}
          </span>
        }
        backTo="/hr/employees"
        backLabel="Employees"
        actions={
          <>
            {can('hr.employees.edit') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuPencil size={12} />}
                onClick={() => navigate(`/hr/employees/${id}/edit`)}
              >
                Edit
              </Button>
            )}
            {can('hr.employees.separate') && employee.status === 'active' && (
              <Button
                variant="danger"
                size="sm"
                icon={<LuUserMinus size={12} />}
                onClick={() => setSeparateOpen(true)}
              >
                Separate
              </Button>
            )}
          </>
        }
      />

      {/* Onboarding is an HR workflow, not part of a department-scoped manager view. */}
      {can('hr.employees.edit') && (
        <div className="px-5 pt-3">
          <OnboardingStepper employeeId={id} />
        </div>
      )}

      {/* Tabs — accessible tab pattern with cursor-pointer and cn() */}
      <Tabs
        className="px-5"
        label="Employee details"
        value={tab}
        onChange={setTab}
        items={TABS.map((t) => ({ key: t, label: t }))}
      />

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
        <div>
          {tab === 'Overview' && <OverviewTab employee={employee} />}
          {tab === 'Employment history' && <EmploymentHistoryTab employee={employee} />}
          {tab === 'Attendance' && <AttendanceTab employeeId={id} />}
          {tab === 'Leaves' && <LeavesTab employeeId={id} />}
          {tab === 'Loans' && <LoansTab employeeId={id} />}
          {tab === 'Documents' && <DocumentsTab employee={employee} />}
          {tab === 'Trainings' && <TrainingsTab employeeId={id} />}
          {tab === 'Skills' && <SkillsTab employeeId={id} />}
          {tab === 'Payroll' && <PayrollTab employeeId={id} />}
          {tab === 'Activity' && <ActivityTab employee={employee} />}
        </div>

        <div className="space-y-4">
          <Panel title="At a glance">
            <dl className="text-sm space-y-2">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">
                  Employee no
                </dt>
                <dd className="font-mono">{employee.employee_no}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Hired</dt>
                <dd className="font-mono">{formatDate(employee.date_hired)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">
                  Pay type
                </dt>
                <dd>{employee.pay_type_label ?? employee.pay_type}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">
                  {employee.pay_type === 'monthly' ? 'Monthly salary' : 'Daily rate'}
                </dt>
                <dd className="font-mono tabular-nums">
                  {formatPeso(
                    employee.pay_type === 'monthly'
                      ? employee.basic_monthly_salary
                      : employee.semi_monthly_rate,
                  )}
                </dd>
              </div>
              {employee.user && (
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted font-medium">
                    Linked user
                  </dt>
                  <dd>{employee.user.email}</dd>
                </div>
              )}
              {currentShift && (
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted font-medium">
                    Current shift
                  </dt>
                  <dd className="font-mono tabular-nums">
                    {currentShift.name} ({currentShift.start_time}–{currentShift.end_time})
                  </dd>
                </div>
              )}
            </dl>
          </Panel>

          {/* U1 — System account section. Renders empty/data states itself
 and is gated by hr.employees.account_status. */}
          <SystemAccountSection
            employeeId={id}
            suggestedEmail={employee.contact?.email ?? undefined}
          />
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
  const { can } = usePermission();
  const qc = useQueryClient();
  const photoMutation = useMutation({
    mutationFn: (file: File) => employeesApi.uploadPhoto(employee.id, file),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hr', 'employee', employee.id] });
      toast.success('Photo updated.');
    },
    onError: () => toast.error('Failed to upload photo.'),
  });
  const deletePhotoMutation = useMutation({
    mutationFn: () => employeesApi.deletePhoto(employee.id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hr', 'employee', employee.id] });
      toast.success('Photo removed.');
    },
  });

  return (
    <div className="space-y-4">
      <Panel title="Personal">
        <div className="flex gap-6">
          <div className="shrink-0">
            <div className="w-20 h-20 rounded-full bg-elevated flex items-center justify-center overflow-hidden">
              {employee.photo_url ? (
                <img
                  src={employee.photo_url}
                  alt={employee.name}
                  className="w-full h-full object-cover"
                />
              ) : (
                <LuCamera size={24} className="text-muted" />
              )}
            </div>
            {can('hr.employees.edit') && (
              <div className="mt-1 flex gap-1">
                <label className="text-2xs text-accent cursor-pointer hover:underline">
                  {employee.photo_url ? 'Change' : 'Upload'}
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    onChange={(e) => {
                      const f = e.target.files?.[0];
                      if (f) photoMutation.mutate(f);
                    }}
                  />
                </label>
                {employee.photo_url && (
                  <button
                    className="text-2xs text-danger-fg hover:underline"
                    onClick={() => deletePhotoMutation.mutate()}
                  >
                    Remove
                  </button>
                )}
              </div>
            )}
          </div>
          <dl className="grid grid-cols-2 gap-4 text-sm flex-1">
            <Item label="Full name" value={employee.full_name} />
            <Item label="Birth date" value={formatDate(employee.birth_date)} mono />
            <Item label="Gender" value={employee.gender_label ?? cap(employee.gender)} />
            <Item
              label="Civil status"
              value={employee.civil_status_label ?? cap(employee.civil_status)}
            />
            <Item label="Nationality" value={employee.nationality} />
          </dl>
        </div>
      </Panel>
      <Panel title="Contact">
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <Item label="Mobile" value={formatMobile(employee.contact.mobile_number)} mono />
          <Item label="Email" value={employee.contact.email} />
          <Item
            label="Address"
            value={[
              employee.address.street,
              employee.address.barangay,
              employee.address.city,
              employee.address.province,
              employee.address.zip_code,
            ]
              .filter(Boolean)
              .join(', ')}
          />
          <Item
            label="Emergency contact"
            value={
              employee.contact.emergency_contact_name
                ? `${employee.contact.emergency_contact_name} (${employee.contact.emergency_contact_relation ?? 'n/a'}) — ${employee.contact.emergency_contact_phone ?? ''}`
                : null
            }
          />
        </dl>
      </Panel>
      <GovIdsPanel employee={employee} />
      <Panel title="Banking">
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <Item label="Bank" value={employee.bank_name} />
          <Item label="Account number" value={employee.bank_account_no} mono />
        </dl>
      </Panel>
    </div>
  );
}

// Field labels and value renderers for employment history diffs.
const HISTORY_FIELD_LABEL: Record<string, string> = {
  department_id: 'Department',
  position_id: 'Position',
  employment_type: 'Employment type',
  pay_type: 'Pay type',
  basic_monthly_salary: 'Monthly salary',
  semi_monthly_rate: 'Semi-monthly rate',
  salary: 'Salary',
  date_regularized: 'Regularized on',
  separation_reason: 'Reason',
  separation_date: 'Effective',
  remarks: 'Remarks',
};

function renderHistoryValue(key: string, value: any): ReactNode {
  if (value === null || value === undefined || value === '')
    return <span className="text-text-subtle">—</span>;
  if (key === 'basic_monthly_salary' || key === 'semi_monthly_rate' || key === 'salary') {
    return <span className="font-mono tabular-nums">{formatPeso(value)}</span>;
  }
  if (key === 'date_regularized' || key === 'separation_date') {
    return <span className="font-mono">{formatDate(value)}</span>;
  }
  if (key === 'employment_type' || key === 'pay_type' || key === 'separation_reason') {
    return <span>{cap(String(value).replace('_', ' '))}</span>;
  }
  if (typeof value === 'object') {
    return <span className="font-mono text-xs">{JSON.stringify(value)}</span>;
  }
  return <span className="font-mono">{String(value)}</span>;
}

function GovIdsPanel({ employee }: { employee: any }) {
  const { can } = usePermission();
  const canView = can('hr.employees.view_sensitive');
  const [revealed, setRevealed] = useState(false);
  const action = canView ? (
    <Button
      variant="ghost"
      size="sm"
      icon={revealed ? <LuEyeOff size={12} /> : <LuEye size={12} />}
      onClick={() => setRevealed((v) => !v)}
    >
      {revealed ? 'Hide' : 'Reveal'}
    </Button>
  ) : null;
  return (
    <Panel
      title="Government IDs"
      meta={canView ? 'Masked. Click reveal to view.' : 'Hidden — insufficient permissions.'}
      actions={action}
    >
      <dl className="grid grid-cols-2 gap-4 text-sm">
        <Item label="SSS" value={maskByKind('sss', employee.sss_no, canView && revealed)} mono />
        <Item
          label="PhilHealth"
          value={maskByKind('philhealth', employee.philhealth_no, canView && revealed)}
          mono
        />
        <Item
          label="Pag-IBIG"
          value={maskByKind('pagibig', employee.pagibig_no, canView && revealed)}
          mono
        />
        <Item label="TIN" value={maskByKind('tin', employee.tin, canView && revealed)} mono />
      </dl>
    </Panel>
  );
}

function EmploymentHistoryTab({ employee }: { employee: any }) {
  const items = (employee.employment_history ?? []) as any[];
  if (items.length === 0) {
    return (
      <EmptyState
        icon="inbox"
        title="No history yet"
        description="Employment changes appear here over time."
      />
    );
  }
  return (
    <Panel title={`Employment history (${items.length})`} noPadding>
      <ul className="divide-y divide-subtle">
        {items.map((h) => {
          const to = h.to_value && typeof h.to_value === 'object' ? h.to_value : {};
          const from = h.from_value && typeof h.from_value === 'object' ? h.from_value : {};
          const keys = Array.from(new Set([...Object.keys(from), ...Object.keys(to)]));
          return (
            <li key={h.id} className="px-4 py-3 text-sm">
              <div className="flex items-center justify-between mb-1.5">
                <span className="font-medium">{cap(h.change_type.replace('_', ' '))}</span>
                <span className="font-mono text-xs text-muted">{formatDate(h.effective_date)}</span>
              </div>
              {h.remarks && <p className="text-xs text-muted mb-2">{h.remarks}</p>}
              {keys.length > 0 && (
                <dl className="grid grid-cols-[160px_1fr] gap-y-1 text-xs">
                  {keys.map((k) => (
                    <div key={k} className="contents">
                      <dt className="text-muted">
                        {HISTORY_FIELD_LABEL[k] ?? cap(k.replace('_', ' '))}
                      </dt>
                      <dd className="flex items-center gap-2 min-w-0">
                        {Object.prototype.hasOwnProperty.call(from, k) && (
                          <>
                            <span className="line-through text-text-subtle truncate">
                              {renderHistoryValue(k, from[k])}
                            </span>
                            <span className="text-text-subtle">→</span>
                          </>
                        )}
                        <span className="truncate">{renderHistoryValue(k, to[k])}</span>
                      </dd>
                    </div>
                  ))}
                </dl>
              )}
            </li>
          );
        })}
      </ul>
    </Panel>
  );
}

function DocumentsTab({ employee }: { employee: any }) {
  const { can } = usePermission();
  const qc = useQueryClient();
  const employeeId = employee.id;
  const [showUpload, setShowUpload] = useState(false);
  // These files back final-pay computation and clearance, so deletion goes
  // through a real confirmation that names the file — not a bare confirm().
  const [deleteTarget, setDeleteTarget] = useState<EmployeeDocument | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const {
    data: docsResp,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ['employee-documents', employeeId],
    queryFn: () => employeeDocumentApi.list(employeeId),
  });
  const { data: documentOptions } = useQuery({
    queryKey: ['employee-document-options', employeeId],
    queryFn: () => employeeDocumentApi.options(employeeId),
    staleTime: 5 * 60_000,
  });
  const docs = docsResp?.data ?? [];

  const uploadMutation = useMutation({
    mutationFn: (data: FormData) => employeeDocumentApi.upload(employeeId, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee-documents', employeeId] });
      qc.invalidateQueries({ queryKey: ['hr', 'employee', employeeId] });
      toast.success('Document uploaded.');
      setShowUpload(false);
    },
    onError: () => toast.error('Failed to upload document.'),
  });

  const deleteMutation = useMutation({
    mutationFn: (docId: string) => employeeDocumentApi.delete(employeeId, docId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee-documents', employeeId] });
      qc.invalidateQueries({ queryKey: ['hr', 'employee', employeeId] });
      toast.success('Document deleted.');
    },
    onError: () => toast.error('Failed to delete document.'),
  });

  const handleUpload = () => {
    const file = fileRef.current?.files?.[0];
    if (!file) return;
    const docType = (document.getElementById('doc-type') as HTMLInputElement)?.value.trim();
    if (!docType) {
      toast.error('Enter a document type.');
      return;
    }
    const fd = new FormData();
    fd.append('file', file);
    fd.append('document_type', docType);
    uploadMutation.mutate(fd);
  };

  if (isLoading) return <SkeletonPanel />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load documents" />;

  return (
    <Panel
      title={`Documents (${docs.length})`}
      noPadding
      actions={
        can('hr.employees.documents.view') ? (
          <Button
            variant="secondary"
            size="sm"
            icon={<LuPlus size={12} />}
            onClick={() => setShowUpload(true)}
          >
            Upload
          </Button>
        ) : null
      }
    >
      {docs.length === 0 ? (
        <div className="p-4">
          <EmptyState icon="file-question" title="No documents" />
        </div>
      ) : (
        <table className={tableCls}>
          <thead>
            <tr className={theadTrCls}>
              <Th>Type</Th>
              <Th>File</Th>
              <Th>Uploaded</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {docs.map((d: any) => (
              <tr key={d.id} className={trCls}>
                <Td>{d.document_type}</Td>
                <Td mono>
                  <a
                    href={employeeDocumentApi.downloadUrl(d.id)}
                    className="text-accent hover:underline"
                    target="_blank"
                    rel="noreferrer"
                  >
                    {d.file_name}
                  </a>
                </Td>
                <Td mono>{formatDateTime(d.uploaded_at)}</Td>
                <Td>
                  {can('hr.employees.edit') && (
                    <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(d)}>
                      Delete
                    </Button>
                  )}
                </Td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {showUpload && (
        <Modal isOpen onClose={() => setShowUpload(false)} title="Upload document">
          <div className="space-y-3 py-2">
            <div>
              <Input
                id="doc-type"
                label="Document type"
                list="employee-document-types"
                placeholder="Enter a document type"
                required
              />
              <datalist id="employee-document-types">
                {(documentOptions?.document_types ?? []).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </datalist>
            </div>
            <div>
              <label className="text-xs text-muted font-medium mb-1 block">File</label>
              <input
                ref={fileRef}
                type="file"
                className="text-sm"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
              />
            </div>
            <ModalFooter>
              <Button
                variant="secondary"
                onClick={() => setShowUpload(false)}
                disabled={uploadMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={handleUpload}
                disabled={uploadMutation.isPending}
                loading={uploadMutation.isPending}
              >
                Upload
              </Button>
            </ModalFooter>
          </div>
        </Modal>
      )}

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        title="Delete this document?"
        description={
          deleteTarget && (
            <>
              <span className="font-mono">{deleteTarget.file_name}</span> (
              {deleteTarget.document_type}) will be permanently removed. Employee documents back
              final-pay computation and clearance, so deleting one may affect those records.
            </>
          )
        }
        confirmLabel="Delete document"
        variant="danger"
        pending={deleteMutation.isPending}
        onConfirm={() => {
          if (!deleteTarget) return;
          deleteMutation.mutate(deleteTarget.id, { onSuccess: () => setDeleteTarget(null) });
        }}
      />
    </Panel>
  );
}

function AttendanceTab({ employeeId }: { employeeId: string }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance', 'attendances', { employee_id: employeeId, per_page: 14 }],
    queryFn: async () => {
      const { attendancesApi } = await import('@/api/attendance/attendances');
      return attendancesApi.list({ employee_id: employeeId, per_page: 14 });
    },
  });
  if (isLoading) return <SkeletonPanel />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load attendance" />;
  const rows = data?.data ?? [];
  if (rows.length === 0) return <EmptyState icon="inbox" title="No attendance records yet" />;
  return (
    <Panel title={`Attendance (last ${rows.length} days)`} noPadding>
      <table className={tableCls}>
        <thead>
          <tr className={theadTrCls}>
            <Th>Date</Th>
            <Th>In</Th>
            <Th>Out</Th>
            <Th align="right">Reg</Th>
            <Th align="right">OT</Th>
            <Th align="right">ND</Th>
            <Th>Status</Th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r: any) => (
            <tr key={r.id} className={trCls}>
              <Td mono>{formatDate(r.date)}</Td>
              <Td mono>{r.time_in ? formatTime(r.time_in) : '—'}</Td>
              <Td mono>{r.time_out ? formatTime(r.time_out) : '—'}</Td>
              <Td align="right" mono>
                {r.regular_hours}
              </Td>
              <Td align="right" mono>
                {r.overtime_hours}
              </Td>
              <Td align="right" mono>
                {r.night_diff_hours}
              </Td>
              <Td>
                <Chip variant={chipVariantForStatus(r.status)}>
                  {r.status_label ?? r.status.replace('_', ' ')}
                </Chip>
              </Td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function LeavesTab({ employeeId }: { employeeId: string }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['leaves', 'requests', { employee_id: employeeId, per_page: 25 }],
    queryFn: async () => {
      const { leaveRequestsApi } = await import('@/api/leave');
      return leaveRequestsApi.list({ employee_id: employeeId, per_page: 25 });
    },
  });
  if (isLoading) return <SkeletonPanel />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load leaves" />;
  const rows = data?.data ?? [];
  if (rows.length === 0) return <EmptyState icon="inbox" title="No leave requests yet" />;
  return (
    <Panel title={`Leave requests (${rows.length})`} noPadding>
      <table className={tableCls}>
        <thead>
          <tr className={theadTrCls}>
            <Th>No</Th>
            <Th>Type</Th>
            <Th>Dates</Th>
            <Th align="right">Days</Th>
            <Th>Status</Th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r: any) => (
            <tr key={r.id} className={trCls}>
              <Td>{r.leave_request_no}</Td>
              <Td>{r.leave_type?.code}</Td>
              <Td mono>
                {formatDate(r.start_date)} → {formatDate(r.end_date)}
              </Td>
              <Td align="right" mono>
                {r.days}
              </Td>
              <Td>
                <Chip variant={chipVariantForStatus(r.status)}>
                  {r.status_label ?? r.status.replace('_', ' ')}
                </Chip>
              </Td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function LoansTab({ employeeId }: { employeeId: string }) {
  const navigate = useNavigate();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['loans', { employee_id: employeeId }],
    queryFn: async () => {
      const { loansApi } = await import('@/api/loans');
      return loansApi.list({ employee_id: employeeId, per_page: 25 });
    },
  });
  if (isLoading) return <SkeletonPanel />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load loans" />;
  const rows = data?.data ?? [];
  if (rows.length === 0) return <EmptyState icon="inbox" title="No loans yet" />;
  return (
    <Panel title={`Loans (${rows.length})`} noPadding>
      <table className={tableCls}>
        <thead>
          <tr className={theadTrCls}>
            <Th>Loan no</Th>
            <Th>Type</Th>
            <Th align="right">Principal</Th>
            <Th align="right">Balance</Th>
            <Th>Status</Th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r: any) => (
            <tr
              key={r.id}
              className={cn(trCls, 'cursor-pointer')}
              onClick={() => navigate(`/hr/loans/${r.id}`)}
            >
              <Td>
                <Link
                  to={`/hr/loans/${r.id}`}
                  onClick={(e) => e.stopPropagation()}
                  className="font-mono text-accent hover:underline"
                >
                  {r.loan_no}
                </Link>
              </Td>
              <Td>
                {r.loan_type_label ?? (r.loan_type === 'company_loan' ? 'Company' : 'Cash advance')}
              </Td>
              <Td align="right" mono>
                {formatPeso(r.principal)}
              </Td>
              <Td align="right" mono>
                {formatPeso(r.balance)}
              </Td>
              <Td>
                <Chip variant={chipVariantForStatus(r.status)}>{r.status_label ?? r.status}</Chip>
              </Td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function ActivityTab({ employee }: { employee: any }) {
  const items = (employee.employment_history ?? []) as any[];
  if (items.length === 0) return <EmptyState icon="inbox" title="No activity yet" />;
  return (
    <Panel title="Recent activity" noPadding>
      <ul className="divide-y divide-subtle">
        {items.slice(0, 20).map((h: any) => (
          <li key={h.id} className="px-4 py-3 flex items-start gap-3">
            <span className="mt-1 w-1.5 h-1.5 rounded-full bg-accent shrink-0" />
            <div className="min-w-0 flex-1">
              <div className="text-sm">{cap(h.change_type.replace('_', ' '))}</div>
              {h.remarks && <div className="text-xs text-muted">{h.remarks}</div>}
            </div>
            <span className="text-xs text-muted font-mono tabular-nums shrink-0">
              {formatDate(h.effective_date)}
            </span>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function TrainingsTab({ employeeId }: { employeeId: string }) {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [showAssign, setShowAssign] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['employee-trainings', employeeId],
    queryFn: () => employeeTrainingsApi.list(employeeId),
  });
  const rows = data?.data ?? [];

  const completeMutation = useMutation({
    mutationFn: (recordId: string) =>
      employeeTrainingsApi.complete(recordId, {
        completed_at: new Date().toISOString().slice(0, 10),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee-trainings', employeeId] });
      toast.success('Training completed.');
    },
    onError: () => toast.error('Failed to complete training.'),
  });

  const cancelMutation = useMutation({
    mutationFn: (recordId: string) => employeeTrainingsApi.cancel(recordId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee-trainings', employeeId] });
      toast.success('Training cancelled.');
    },
    onError: () => toast.error('Failed to cancel training.'),
  });

  if (isLoading) return <SkeletonPanel />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load trainings" />;

  return (
    <>
      <Panel
        title={`Trainings (${rows.length})`}
        noPadding
        actions={
          can('hr.employees.trainings.manage') ? (
            <Button
              variant="secondary"
              size="sm"
              icon={<LuPlus size={12} />}
              onClick={() => setShowAssign(true)}
            >
              Assign
            </Button>
          ) : null
        }
      >
        {rows.length === 0 ? (
          <div className="p-4">
            <EmptyState icon="file-text" title="No trainings assigned" />
          </div>
        ) : (
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Training</Th>
                <Th>Scheduled</Th>
                <Th>Completed</Th>
                <Th>Expires</Th>
                <Th>Status</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {rows.map((r: any) => (
                <tr key={r.id} className={trCls}>
                  <Td>{r.training?.name ?? '—'}</Td>
                  <Td mono>{r.scheduled_for ?? '—'}</Td>
                  <Td mono>{r.completed_at ?? '—'}</Td>
                  <Td mono>{r.expires_at ?? '—'}</Td>
                  <Td>
                    <Chip variant={chipVariantForStatus(r.status)}>{r.status_label}</Chip>
                  </Td>
                  <Td>
                    {r.status === 'scheduled' && can('hr.employees.trainings.manage') && (
                      <div className="flex gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          icon={<LuCheck size={12} />}
                          onClick={() => completeMutation.mutate(r.id)}
                        />
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => cancelMutation.mutate(r.id)}
                        >
                          Cancel
                        </Button>
                      </div>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
      {showAssign && (
        <AssignTrainingModal employeeId={employeeId} onClose={() => setShowAssign(false)} />
      )}
    </>
  );
}

function AssignTrainingModal({ employeeId, onClose }: { employeeId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const { data: trainingsResp } = useQuery({
    queryKey: ['hr', 'trainings', 'all'],
    queryFn: () => trainingsApi.list({ per_page: 200 }),
  });
  const trainings = trainingsResp?.data ?? [];
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<{ training_id: string; scheduled_for: string }>({
    defaultValues: { scheduled_for: new Date().toISOString().slice(0, 10) },
  });
  const mutation = useMutation({
    mutationFn: (d: { training_id: string; scheduled_for: string }) =>
      employeeTrainingsApi.assign(employeeId, d),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee-trainings', employeeId] });
      toast.success('Training assigned.');
      onClose();
    },
    onError: () => toast.error('Failed to assign training.'),
  });
  return (
    <Modal isOpen onClose={onClose} title="Assign training">
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-3 py-2">
        <div>
          <label className="text-xs text-muted font-medium mb-1 block">Training</label>
          <select
            {...register('training_id', { required: 'Required' })}
            className="w-full h-9 px-3 rounded-md border border-default bg-canvas text-sm"
          >
            <option value="">— Select —</option>
            {trainings
              .filter((t) => t.is_active)
              .map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
          </select>
          {errors.training_id && (
            <p className="text-xs text-danger-fg mt-1">{errors.training_id.message}</p>
          )}
        </div>
        <Input label="Scheduled for" type="date" {...register('scheduled_for')} />
        <ModalFooter>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={mutation.isPending}
            loading={mutation.isPending}
          >
            Assign
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  );
}

function SkillsTab({ employeeId }: { employeeId: string }) {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [showAssign, setShowAssign] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['employee-skills', employeeId],
    queryFn: () => employeeSkillsApi.list(employeeId),
  });
  const rows = data?.data ?? [];

  const removeMutation = useMutation({
    mutationFn: (recordId: string) => employeeSkillsApi.remove(recordId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee-skills', employeeId] });
      toast.success('Skill removed.');
    },
    onError: () => toast.error('Failed to remove skill.'),
  });

  if (isLoading) return <SkeletonPanel />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load skills" />;

  return (
    <>
      <Panel
        title={`Skills (${rows.length})`}
        noPadding
        actions={
          can('hr.employees.trainings.manage') ? (
            <Button
              variant="secondary"
              size="sm"
              icon={<LuPlus size={12} />}
              onClick={() => setShowAssign(true)}
            >
              Assign
            </Button>
          ) : null
        }
      >
        {rows.length === 0 ? (
          <div className="p-4">
            <EmptyState icon="file-text" title="No skills assigned" />
          </div>
        ) : (
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Skill</Th>
                <Th>Category</Th>
                <Th>Level</Th>
                <Th>Acquired</Th>
                <Th>Expires</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {rows.map((r: any) => (
                <tr key={r.id} className={trCls}>
                  <Td>{r.skill?.name ?? '—'}</Td>
                  <Td>{r.skill?.category ?? '—'}</Td>
                  <Td>{r.proficiency_level ?? '—'}</Td>
                  <Td mono>{r.acquired_date ?? '—'}</Td>
                  <Td mono>{r.expires_at ?? '—'}</Td>
                  <Td>
                    {can('hr.employees.trainings.manage') && (
                      <Button variant="ghost" size="sm" onClick={() => removeMutation.mutate(r.id)}>
                        Remove
                      </Button>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
      {showAssign && (
        <AssignSkillModal employeeId={employeeId} onClose={() => setShowAssign(false)} />
      )}
    </>
  );
}

function AssignSkillModal({ employeeId, onClose }: { employeeId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const { data: skillsResp } = useQuery({
    queryKey: ['hr', 'skills', 'all'],
    queryFn: () => skillsApi.list({ per_page: 200 }),
  });
  const { data: employeeOptions } = useQuery({
    queryKey: ['hr', 'employee-options'],
    queryFn: () => employeesApi.options(),
    staleTime: 5 * 60_000,
  });
  const skills = skillsResp?.data ?? [];
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<{ skill_id: string; proficiency_level: string }>();
  const mutation = useMutation({
    mutationFn: (d: { skill_id: string; proficiency_level: string }) =>
      employeeSkillsApi.assign(employeeId, d),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employee-skills', employeeId] });
      toast.success('Skill assigned.');
      onClose();
    },
    onError: () => toast.error('Failed to assign skill.'),
  });
  return (
    <Modal isOpen onClose={onClose} title="Assign skill">
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-3 py-2">
        <div>
          <label className="text-xs text-muted font-medium mb-1 block">Skill</label>
          <select
            {...register('skill_id', { required: 'Required' })}
            className="w-full h-9 px-3 rounded-md border border-default bg-canvas text-sm"
          >
            <option value="">— Select —</option>
            {skills
              .filter((s) => s.is_active)
              .map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
          </select>
          {errors.skill_id && (
            <p className="text-xs text-danger-fg mt-1">{errors.skill_id.message}</p>
          )}
        </div>
        <div>
          <label className="text-xs text-muted font-medium mb-1 block">Proficiency</label>
          <select
            {...register('proficiency_level')}
            className="w-full h-9 px-3 rounded-md border border-default bg-canvas text-sm"
          >
            <option value="">— Select —</option>
            {(employeeOptions?.skill_levels ?? []).map((level) => (
              <option key={level.value} value={level.value}>
                {level.label}
              </option>
            ))}
          </select>
        </div>
        <ModalFooter>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={mutation.isPending}
            loading={mutation.isPending}
          >
            Assign
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  );
}

function PayrollTab({ employeeId }: { employeeId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['payrolls', { employee_id: employeeId, per_page: 25 }],
    queryFn: async () => {
      const { client } = await import('@/api/client');
      return client
        .get('/payrolls', { params: { employee_id: employeeId, per_page: 25 } })
        .then((r) => r.data);
    },
  });
  const rows = data?.data ?? [];

  if (isLoading) return <SkeletonPanel />;
  if (rows.length === 0)
    return (
      <Panel title="Payroll history">
        <EmptyState icon="file-text" title="No payroll records yet" />
      </Panel>
    );
  return (
    <Panel title={`Payroll history (${rows.length})`} noPadding>
      <table className={tableCls}>
        <thead>
          <tr className={theadTrCls}>
            <Th>Period</Th>
            <Th>Cutoff</Th>
            <Th align="right">Basic</Th>
            <Th align="right">Gross</Th>
            <Th align="right">Deductions</Th>
            <Th align="right">Net</Th>
            <Th>Status</Th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r: any) => (
            <tr key={r.id} className={trCls}>
              <Td mono>{r.payroll_period?.label ?? r.payroll_period_id}</Td>
              <Td mono>
                {r.payroll_period?.period_start?.slice(0, 10)} –{' '}
                {r.payroll_period?.period_end?.slice(0, 10)}
              </Td>
              <Td align="right" mono>
                {formatPeso(r.basic_pay)}
              </Td>
              <Td align="right" mono>
                {formatPeso(r.gross_pay)}
              </Td>
              <Td align="right" mono>
                {formatPeso(r.total_deductions)}
              </Td>
              <Td align="right" mono className="font-medium">
                {formatPeso(r.net_pay)}
              </Td>
              <Td>
                <Chip variant={chipVariantForStatus(r.payroll_period?.status ?? '')}>
                  {r.payroll_period?.status_label ?? r.payroll_period?.status ?? '—'}
                </Chip>
              </Td>
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
      <dd className={mono ? 'font-mono tabular-nums' : ''}>
        {value || <span className="text-text-subtle">—</span>}
      </dd>
    </div>
  );
}

function cap(s?: string | null): string {
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
}

const separateSchema = z.object({
  separation_reason: z.string().min(1, 'Reason is required'),
  separation_date: z.string().min(1, 'Required'),
  remarks: z.string().max(2000).optional().or(z.literal('')),
});

type SeparateFormValues = z.infer<typeof separateSchema>;

function SeparateModal({
  employeeId,
  fullName,
  onClose,
  onSeparated,
}: {
  employeeId: string;
  fullName: string;
  onClose: () => void;
  onSeparated: () => void;
}) {
  const { data: employeeOptions } = useQuery({
    queryKey: ['hr', 'employee-options'],
    queryFn: () => employeesApi.options(),
  });
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<SeparateFormValues>({
    resolver: zodResolver(separateSchema),
    defaultValues: {
      separation_reason: '',
      separation_date: new Date().toISOString().slice(0, 10),
    },
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
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<SeparateFormValues>())}
        className="space-y-3 py-2"
      >
        <p className="text-sm text-muted">
          Marking <span className="font-medium text-primary">{fullName}</span> as separated. This is
          recorded in their employment history.
        </p>
        <Select
          label="Reason"
          required
          {...register('separation_reason')}
          error={errors.separation_reason?.message}
        >
          <option value="">— Select —</option>
          {(employeeOptions?.separation_reasons ?? []).map((reason) => (
            <option key={reason.value} value={reason.value}>
              {reason.label}
            </option>
          ))}
        </Select>
        <Input
          label="Effective date"
          type="date"
          required
          {...register('separation_date')}
          error={errors.separation_date?.message}
        />
        <Textarea
          label="Remarks"
          {...register('remarks')}
          error={errors.remarks?.message}
          rows={3}
        />
        <ModalFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={onClose}
            disabled={isSubmitting || mutation.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="danger"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Separating…' : 'Separate'}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  );
}
