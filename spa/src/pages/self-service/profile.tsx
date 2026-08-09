/**
 * Task SS2 — Self-service profile.
 *
 * Employees update their own contact, address, and emergency-contact details;
 * each change becomes a profile-update request pending HR approval (never
 * auto-applied). Bank-account changes are financial and require HR + Finance
 * dual approval — surfaced as a separate "Request update" flow.
 *
 * Layout follows the desktop record-detail anatomy used by
 * [`EmployeeDetailPage`](../hr/employees/detail.tsx): PageHeader with actions,
 * a wide content column of Panels, and a 320px right rail for identity,
 * request history, and account preferences. The page renders inside AppLayout
 * (sidebar + topbar), so it is a web record page — not a phone settings screen.
 */
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, LogOut, Pencil } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { selfServiceApi } from '@/api/self-service';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { PageHeader } from '@/components/layout/PageHeader';
import { Avatar } from '@/components/ui/Avatar';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { LinkButton } from '@/components/ui/LinkButton';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { formatDate } from '@/lib/formatDate';
import type { ApiValidationError } from '@/types';
import type { ProfileUpdateRequestRecord, SelfServiceProfile } from '@/types/self-service';

type FieldDef = { key: string; label: string; type?: string; placeholder?: string; mono?: boolean };

const STATUS_CHIP: Record<string, 'success' | 'warning' | 'info' | 'danger' | 'neutral'> = {
 pending: 'warning',
 pending_finance: 'info',
 approved: 'success',
 rejected: 'danger',
};

const FIELD_LABELS: Record<string, string> = {
 mobile_number: 'Mobile',
 email: 'Email',
 street_address: 'Street',
 barangay: 'Barangay',
 city: 'City',
 province: 'Province',
 zip_code: 'ZIP code',
 emergency_contact_name: 'Emergency contact',
 emergency_contact_relation: 'Relationship',
 emergency_contact_phone: 'Emergency phone',
 bank_name: 'Bank',
 bank_account_no: 'Bank account no.',
};

const CONTACT_FIELDS: FieldDef[] = [
 { key: 'mobile_number', label: 'Mobile', placeholder: '09XX-XXX-XXXX', mono: true },
 { key: 'email', label: 'Email', type: 'email' },
];

const EMERGENCY_FIELDS: FieldDef[] = [
 { key: 'emergency_contact_name', label: 'Name' },
 { key: 'emergency_contact_relation', label: 'Relationship' },
 { key: 'emergency_contact_phone', label: 'Phone', mono: true },
];

const ADDRESS_FIELDS: FieldDef[] = [
 { key: 'street_address', label: 'Street' },
 { key: 'barangay', label: 'Barangay' },
 { key: 'city', label: 'City' },
 { key: 'province', label: 'Province' },
 { key: 'zip_code', label: 'ZIP code', mono: true },
];

export default function SelfServiceProfilePage() {
 const queryClient = useQueryClient();
 const navigate = useNavigate();
 const user = useAuthStore((s) => s.user);
 const logout = useAuthStore((s) => s.logout);

 const { data: profile, isLoading, isError, refetch } = useQuery({
 queryKey: ['self-service', 'profile'],
 queryFn: () => selfServiceApi.profile(),
 });

 const { data: requests } = useQuery({
 queryKey: ['self-service', 'profile-requests'],
 queryFn: () => selfServiceApi.profileUpdateRequests(),
 });

 const pendingFields = new Set(
 (requests ?? [])
 .filter((r) => r.status === 'pending' || r.status === 'pending_finance')
 .flatMap((r) => Object.keys(r.changes)),
 );

 const invalidate = () => {
 queryClient.invalidateQueries({ queryKey: ['self-service', 'profile-requests'] });
 };

 const pendingCount = (requests ?? []).filter(
 (r) => r.status === 'pending' || r.status === 'pending_finance',
 ).length;

 const subtitle = profile
 ? [profile.employee_no, profile.position, profile.department].filter(Boolean).join(' · ')
 : 'Your contact details, bank account, and account preferences';

 return (
 <div>
 <PageHeader
 title={profile?.full_name ?? 'My Profile'}
 subtitle={subtitle}
 backTo="/self-service"
 backLabel="Self-service"
 breadcrumbs={[{ label: 'Self-service', href: '/self-service' }, { label: 'Profile' }]}
 actions={
 <>
 <Button
 variant="secondary"
 size="sm"
 icon={<KeyRound size={12} />}
 onClick={() => navigate('/change-password')}
 >
 Change password
 </Button>
 <Button
 variant="secondary"
 size="sm"
 icon={<LogOut size={12} />}
 onClick={() => logout()}
 >
 Sign out
 </Button>
 </>
 }
 />

 <div className="px-5 py-4">
 {/* LOADING */}
 {isLoading && !profile && (
 <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-4 items-start">
 <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
 {[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-44 rounded-md" />)}
 </div>
 <div className="space-y-4">
 {[1, 2].map((i) => <SkeletonBlock key={i} className="h-40 rounded-md" />)}
 </div>
 </div>
 )}

 {/* ERROR */}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Couldn't load your profile"
 description="An error occurred while loading your record. Please try again."
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {profile && (
 <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-4 items-start">
 {/* ─── Main column ─── */}
 <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
 <EditablePanel
 title="Contact"
 fields={CONTACT_FIELDS}
 values={profile}
 pendingFields={pendingFields}
 onSubmitted={invalidate}
 />

 <EditablePanel
 title="Emergency contact"
 fields={EMERGENCY_FIELDS}
 values={profile}
 pendingFields={pendingFields}
 onSubmitted={invalidate}
 />

 <EditablePanel
 title="Address"
 fields={ADDRESS_FIELDS}
 values={profile}
 pendingFields={pendingFields}
 onSubmitted={invalidate}
 className="lg:col-span-2"
 columns={2}
 />

 <BankPanel
 bankName={profile.bank_name}
 accountLast4={profile.bank_account_last4}
 pending={pendingFields.has('bank_account_no') || pendingFields.has('bank_name')}
 onSubmitted={invalidate}
 />

 <Panel title="Government IDs" meta="managed by HR" noPadding>
 <dl className="divide-y divide-subtle">
 <FieldRow label="SSS" value={profile.sss_no_last4} mono />
 <FieldRow label="PhilHealth" value={profile.philhealth_no_last4} mono />
 <FieldRow label="Pag-IBIG" value={profile.pagibig_no_last4} mono />
 <FieldRow label="TIN" value={profile.tin_last4} mono />
 </dl>
 </Panel>
 </div>

 {/* ─── Right rail ─── */}
 <div className="space-y-4">
 <IdentityPanel profile={profile} email={user?.email ?? profile.email} />
 <UpdateRequestsPanel requests={requests ?? []} pendingCount={pendingCount} />
 <PreferencesPanel />
 </div>
 </div>
 )}
 </div>
 </div>
 );
}

/* ───────────────────────── Shared rows ───────────────────────── */

/**
 * One label/value line. Fixed label column so values line up down the panel —
 * the desktop definition-list look, not the phone-settings right-aligned row.
 */
function FieldRow({
 label,
 value,
 mono,
 pending,
}: {
 label: string;
 value: string | null;
 mono?: boolean;
 pending?: boolean;
}) {
 return (
 <div className="grid grid-cols-[120px_minmax(0,1fr)] items-baseline gap-3 px-4 py-2">
 <dt className="text-xs text-muted truncate">{label}</dt>
 <dd className="flex items-baseline gap-2 min-w-0">
 <span className={`text-sm text-primary truncate ${mono ? 'font-mono tabular-nums' : ''}`}>
 {value || '—'}
 </span>
 {pending && <Chip variant="warning">Pending</Chip>}
 </dd>
 </div>
 );
}

/* ───────────────────────── Identity ───────────────────────── */

function IdentityPanel({ profile, email }: { profile: SelfServiceProfile; email: string | null }) {
 return (
 <Panel title="At a glance">
 <div className="flex items-center gap-3 mb-3">
 <Avatar name={profile.full_name} src={profile.photo_url} size="lg" className="h-10 w-10 text-sm" />
 <div className="min-w-0">
 <div className="text-sm font-medium text-primary truncate">{profile.full_name}</div>
 <div className="text-xs text-muted font-mono tabular-nums truncate">{profile.employee_no}</div>
 </div>
 </div>
 <dl className="text-sm space-y-2">
 {profile.profile_completeness && profile.profile_completeness.percent < 100 && (
 <div className="mb-3 rounded-md border border-warning bg-warning-bg px-3 py-2 text-xs text-warning-fg">
 Profile {profile.profile_completeness.percent}% complete. Missing details can be submitted for HR review from the panels below.
 </div>
 )}
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Position</dt>
 <dd className="text-primary">{profile.position ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Department</dt>
 <dd className="text-primary">{profile.department ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Employment type</dt>
 <dd className="text-primary">{profile.employment_type_label ?? profile.employment_type ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Pay type</dt>
 <dd className="text-primary">{profile.pay_type_label ?? profile.pay_type ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Employment status</dt>
 <dd className="text-primary">{profile.status_label ?? profile.status ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Birth date</dt>
 <dd className="font-mono tabular-nums text-primary">
 {profile.birth_date ? formatDate(profile.birth_date) : '—'}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Nationality</dt>
 <dd className="text-primary">{profile.nationality ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Gender</dt>
 <dd className="text-primary">{profile.gender_label ?? profile.gender ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Civil status</dt>
 <dd className="text-primary">{profile.civil_status_label ?? profile.civil_status ?? '—'}</dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Date hired</dt>
 <dd className="font-mono tabular-nums text-primary">
 {profile.date_hired ? formatDate(profile.date_hired) : '—'}
 </dd>
 </div>
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">
 {profile.date_regularized ? 'Date regularized' : profile.expected_regularization_date ? 'Expected regularization' : 'Date regularized'}
 </dt>
 <dd className="font-mono tabular-nums text-primary">
 {profile.date_regularized
 ? formatDate(profile.date_regularized)
 : profile.expected_regularization_date
 ? formatDate(profile.expected_regularization_date)
 : '—'}
 </dd>
 </div>
 {email && (
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Sign-in email</dt>
 <dd className="text-primary truncate">{email}</dd>
 </div>
 )}
 </dl>
 </Panel>
 );
}

/* ───────────────────────── Update requests ───────────────────────── */

function UpdateRequestsPanel({
 requests,
 pendingCount,
}: {
 requests: ProfileUpdateRequestRecord[];
 pendingCount: number;
}) {
 return (
 <Panel
 title="Update requests"
 meta={requests.length > 0 ? `${pendingCount} pending · ${requests.length} total` : undefined}
 noPadding
 >
 {requests.length === 0 ? (
 <div className="px-4 py-4">
 <EmptyState
 size="compact"
 icon="inbox"
 title="No change requests"
 description="Edits you submit appear here until HR reviews them."
 />
 </div>
 ) : (
 <ul className="divide-y divide-subtle">
 {requests.slice(0, 8).map((r) => (
 <li key={r.id} className="px-4 py-2.5 flex items-start justify-between gap-2">
 <div className="min-w-0">
 <div className="text-xs text-secondary truncate">
 {Object.keys(r.changes).map((k) => FIELD_LABELS[k] ?? k).join(', ')}
 </div>
 <div className="text-2xs text-muted font-mono tabular-nums">
 {r.created_at ? formatDate(r.created_at) : '—'}
 </div>
 </div>
 <Chip variant={STATUS_CHIP[r.status] ?? 'neutral'}>
 {r.status_label ?? r.status}
 </Chip>
 </li>
 ))}
 </ul>
 )}
 </Panel>
 );
}

/* ───────────────────────── Preferences ───────────────────────── */

function PreferencesPanel() {
 const { mode, setMode } = useThemeStore();

 return (
 <Panel title="Preferences">
 <div className="space-y-3">
 <div>
 <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1.5">Theme</div>
 <SegmentedControl
 label="Theme"
 value={mode}
 onChange={setMode}
 options={[
 { value: 'light', label: 'Light' },
 { value: 'dark', label: 'Dark' },
 { value: 'system', label: 'System' },
 ]}
 />
 </div>
 <div className="pt-1 border-t border-subtle">
 <Link
 to="/self-service/notification-preferences"
 className="text-xs text-link hover:underline"
 >
 Notification preferences →
 </Link>
 </div>
 </div>
 </Panel>
 );
}

/* ───────────────────────── Editable block ───────────────────────── */

function EditablePanel({
 title,
 fields,
 values,
 pendingFields,
 onSubmitted,
 className,
 columns = 1,
}: {
 title: string;
 fields: FieldDef[];
 values: SelfServiceProfile;
 pendingFields: Set<string>;
 onSubmitted: () => void;
 className?: string;
 /** Field columns while editing — address gets 2, short blocks get 1. */
 columns?: 1 | 2;
}) {
 const [editing, setEditing] = useState(false);
 const [draft, setDraft] = useState<Record<string, string>>({});
 const [error, setError] = useState<string | null>(null);

 const get = (key: string): string =>
 ((values as unknown as Record<string, unknown>)[key] as string) ?? '';

 const startEdit = () => {
 const init: Record<string, string> = {};
 fields.forEach((f) => { init[f.key] = get(f.key); });
 setDraft(init);
 setError(null);
 setEditing(true);
 };

 const mutation = useMutation({
 mutationFn: () => {
 // Only send fields the user actually changed.
 const changes: Record<string, string> = {};
 fields.forEach((f) => {
 const next = draft[f.key]?.trim() ?? '';
 const current = get(f.key).trim();
 if (next !== current) changes[f.key] = next;
 });
 if (Object.keys(changes).length === 0) {
 return Promise.reject(new Error('no_changes'));
 }
 return selfServiceApi.requestProfileUpdate(changes);
 },
 onSuccess: () => {
 toast.success('Change request submitted for HR review.');
 setEditing(false);
 onSubmitted();
 },
 onError: (err: unknown) => {
 if (err instanceof Error && err.message === 'no_changes') {
 setError('Nothing changed.');
 return;
 }
 const ax = err as AxiosError<ApiValidationError>;
 if (ax.response?.status === 422 && ax.response.data?.errors) {
 setError(Object.values(ax.response.data.errors)[0]?.[0] ?? 'Please check your input.');
 } else {
 toast.error('Failed to submit change request.');
 }
 },
 });

 return (
 <Panel
 title={title}
 className={className}
 noPadding={!editing}
 actions={
 !editing ? (
 <LinkButton onClick={startEdit} icon={<Pencil size={12} />} className="text-xs">
 Edit
 </LinkButton>
 ) : undefined
 }
 >
 {!editing ? (
 <dl className="divide-y divide-subtle">
 {fields.map((f) => (
 <FieldRow
 key={f.key}
 label={f.label}
 value={get(f.key)}
 mono={f.mono}
 pending={pendingFields.has(f.key)}
 />
 ))}
 </dl>
 ) : (
 <div className="space-y-3">
 <div className={columns === 2 ? 'grid grid-cols-1 sm:grid-cols-2 gap-3' : 'space-y-3'}>
 {fields.map((f) => (
 <Input
 key={f.key}
 label={f.label}
 type={f.type ?? 'text'}
 placeholder={f.placeholder}
 value={draft[f.key] ?? ''}
 onChange={(e) => setDraft((d) => ({ ...d, [f.key]: e.target.value }))}
 />
 ))}
 </div>
 {error && <p className="text-xs text-danger-fg">{error}</p>}
 <ModalFooter className="justify-between">
 <p className="text-2xs text-muted">Changes are reviewed by HR before they take effect.</p>
 <div className="flex gap-2 shrink-0">
 <Button variant="secondary" size="sm" onClick={() => setEditing(false)} disabled={mutation.isPending}>
 Cancel
 </Button>
 <Button
 variant="primary"
 size="sm"
 onClick={() => mutation.mutate()}
 disabled={mutation.isPending}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Submitting…' : 'Submit for review'}
 </Button>
 </div>
 </ModalFooter>
 </div>
 )}
 </Panel>
 );
}

/* ───────────────────────── Bank account ───────────────────────── */

function BankPanel({
 bankName,
 accountLast4,
 pending,
 onSubmitted,
}: {
 bankName: string | null;
 accountLast4: string | null;
 pending: boolean;
 onSubmitted: () => void;
}) {
 const [open, setOpen] = useState(false);
 const [bank, setBank] = useState('');
 const [account, setAccount] = useState('');
 const [error, setError] = useState<string | null>(null);

 const mutation = useMutation({
 mutationFn: () => {
 const changes: Record<string, string> = {};
 if (bank.trim()) changes.bank_name = bank.trim();
 if (account.trim()) changes.bank_account_no = account.trim();
 if (!changes.bank_account_no) return Promise.reject(new Error('no_account'));
 return selfServiceApi.requestProfileUpdate(changes, 'Bank account change');
 },
 onSuccess: () => {
 toast.success('Bank change submitted — requires HR + Finance approval.');
 setOpen(false);
 setBank('');
 setAccount('');
 onSubmitted();
 },
 onError: (err: unknown) => {
 if (err instanceof Error && err.message === 'no_account') {
 setError('Enter the new account number.');
 return;
 }
 const ax = err as AxiosError<ApiValidationError>;
 if (ax.response?.status === 422 && ax.response.data?.errors) {
 setError(Object.values(ax.response.data.errors)[0]?.[0] ?? 'Please check your input.');
 } else {
 toast.error('Failed to submit bank change.');
 }
 },
 });

 return (
 <Panel
 title="Bank account"
 meta="HR + Finance approval"
 noPadding
 actions={
 !pending ? (
 <LinkButton onClick={() => { setError(null); setOpen(true); }} className="text-xs">
 Request update
 </LinkButton>
 ) : undefined
 }
 >
 <dl className="divide-y divide-subtle">
 <FieldRow label="Bank" value={bankName} pending={pending} />
 <FieldRow label="Account" value={accountLast4} mono />
 </dl>

 <Modal
 isOpen={open}
 onClose={() => setOpen(false)}
 title="Request Bank Account Update"
 size="md"
 >
 <div className="space-y-3 py-4">
 <p className="text-xs text-muted">
 Bank changes affect payroll disbursement and require approval from
 both HR and Finance. Your current account stays in use until approved.
 </p>
 <Input
 label="Bank name"
 value={bank}
 onChange={(e) => setBank(e.target.value)}
 placeholder="Enter bank name"
 />
 <Input
 label="New account number"
 value={account}
 onChange={(e) => setAccount(e.target.value)}
 placeholder="Account number"
 className="font-mono"
 />
 {error && <p className="text-xs text-danger-fg">{error}</p>}
 <ModalFooter>
 <Button variant="secondary" onClick={() => setOpen(false)} disabled={mutation.isPending}>
 Cancel
 </Button>
 <Button
 variant="primary"
 onClick={() => mutation.mutate()}
 disabled={mutation.isPending}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Submitting…' : 'Submit request'}
 </Button>
 </ModalFooter>
 </div>
 </Modal>
 </Panel>
 );
}
