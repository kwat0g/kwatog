/**
 * Task SS2 — Self-service profile.
 *
 * Employees update their own contact, address, and emergency-contact details;
 * each change becomes a profile-update request pending HR approval (never
 * auto-applied). Bank-account changes are financial and require HR + Finance
 * dual approval — surfaced as a separate "Request Update" flow.
 *
 * Also hosts account settings (theme, notification preferences, change
 * password, sign out) so the bottom-nav "Me" tab is a complete hub.
 */
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { selfServiceApi } from '@/api/self-service';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { ApiValidationError } from '@/types';
import type { ProfileUpdateRequestRecord, SelfServiceProfile } from '@/types/self-service';

type FieldDef = { key: string; label: string; type?: string; placeholder?: string };

const STATUS_CHIP: Record<string, 'success' | 'warning' | 'info' | 'danger' | 'neutral'> = {
  pending: 'warning',
  pending_finance: 'info',
  approved: 'success',
  rejected: 'danger',
};

const STATUS_LABEL: Record<string, string> = {
  pending: 'Pending HR',
  pending_finance: 'Awaiting Finance',
  approved: 'Approved',
  rejected: 'Rejected',
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

export default function SelfServiceProfilePage() {
  const queryClient = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const { mode, setMode } = useThemeStore();

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

  return (
    <div className="px-4 py-4 space-y-4">
      <h1 className="text-base font-medium">My profile</h1>

      {/* LOADING */}
      {isLoading && !profile && (
        <div className="space-y-2">
          {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-24 rounded-md" />)}
        </div>
      )}

      {/* ERROR */}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Couldn't load your profile"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {profile && (
        <>
          {/* Header card */}
          <section className="rounded-md border border-default bg-surface p-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-full bg-elevated flex items-center justify-center text-sm font-medium">
                {profile.full_name.slice(0, 2).toUpperCase()}
              </div>
              <div className="min-w-0">
                <div className="text-sm font-medium truncate">{profile.full_name}</div>
                <div className="text-xs text-muted font-mono tabular-nums truncate">
                  {profile.employee_no}
                </div>
                <div className="text-xs text-muted truncate">
                  {profile.position ?? '—'} · {profile.department ?? '—'}
                </div>
              </div>
            </div>
          </section>

          {/* Pending requests banner */}
          {requests && requests.length > 0 && (
            <PendingRequests requests={requests} />
          )}

          {/* Editable: Contact */}
          <EditableBlock
            title="Contact"
            fields={[
              { key: 'mobile_number', label: 'Mobile', placeholder: '09XX-XXX-XXXX' },
              { key: 'email', label: 'Email', type: 'email', placeholder: 'you@example.com' },
            ]}
            values={profile}
            pendingFields={pendingFields}
            onSubmitted={invalidate}
          />

          {/* Editable: Address */}
          <EditableBlock
            title="Address"
            fields={[
              { key: 'street_address', label: 'Street' },
              { key: 'barangay', label: 'Barangay' },
              { key: 'city', label: 'City' },
              { key: 'province', label: 'Province' },
              { key: 'zip_code', label: 'ZIP code' },
            ]}
            values={profile}
            pendingFields={pendingFields}
            onSubmitted={invalidate}
          />

          {/* Editable: Emergency contact */}
          <EditableBlock
            title="Emergency contact"
            fields={[
              { key: 'emergency_contact_name', label: 'Name' },
              { key: 'emergency_contact_relation', label: 'Relationship' },
              { key: 'emergency_contact_phone', label: 'Phone' },
            ]}
            values={profile}
            pendingFields={pendingFields}
            onSubmitted={invalidate}
          />

          {/* Bank account (financial — HR + Finance approval) */}
          <BankBlock
            bankName={profile.bank_name}
            accountLast4={profile.bank_account_last4}
            pending={pendingFields.has('bank_account_no') || pendingFields.has('bank_name')}
            onSubmitted={invalidate}
          />

          {/* Government IDs — masked, read-only (HR-only changes) */}
          <section className="rounded-md border border-default bg-canvas">
            <div className="px-3 py-2 border-b border-subtle text-2xs uppercase tracking-wider text-muted font-medium">
              Government IDs · managed by HR
            </div>
            <dl className="divide-y divide-subtle">
              <ReadOnlyRow label="SSS" value={profile.sss_no_last4} />
              <ReadOnlyRow label="PhilHealth" value={profile.philhealth_no_last4} />
              <ReadOnlyRow label="Pag-IBIG" value={profile.pagibig_no_last4} />
              <ReadOnlyRow label="TIN" value={profile.tin_last4} />
            </dl>
          </section>
        </>
      )}

      {/* Account settings hub */}
      <section className="rounded-md border border-default bg-canvas">
        <div className="px-3 py-2 border-b border-subtle text-2xs uppercase tracking-wider text-muted font-medium">
          Theme
        </div>
        <div className="grid grid-cols-3 gap-1 p-2">
          {(['light', 'dark', 'system'] as const).map((m) => (
            <button
              key={m}
              onClick={() => setMode(m)}
              className={`h-8 px-3 rounded-md text-sm capitalize ${
                mode === m ? 'bg-elevated text-primary font-medium' : 'text-muted'
              }`}
            >
              {m}
            </button>
          ))}
        </div>
      </section>

      <section className="rounded-md border border-default bg-canvas overflow-hidden">
        <Link to="/self-service/documents" className="block px-3 py-3 hover:bg-elevated text-sm">
          My documents
        </Link>
        <Link to="/self-service/overtime" className="block px-3 py-3 hover:bg-elevated text-sm border-t border-subtle">
          Overtime requests
        </Link>
        <Link to="/self-service/notification-preferences" className="block px-3 py-3 hover:bg-elevated text-sm border-t border-subtle">
          Notification preferences
        </Link>
        <Link to="/change-password" className="block px-3 py-3 hover:bg-elevated text-sm border-t border-subtle">
          Change password
        </Link>
      </section>

      <Button variant="danger" className="w-full" onClick={() => logout()}>
        Sign out
      </Button>

      {user?.email && (
        <p className="text-2xs text-muted text-center">{user.email}</p>
      )}
    </div>
  );
}

/* ───────────────────────── Sub-components ───────────────────────── */

function ReadOnlyRow({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex items-center justify-between px-3 py-2.5">
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="text-sm font-mono tabular-nums">{value ?? '—'}</dd>
    </div>
  );
}

function PendingRequests({ requests }: { requests: ProfileUpdateRequestRecord[] }) {
  return (
    <section className="rounded-md border border-default bg-canvas">
      <div className="px-3 py-2 border-b border-subtle text-2xs uppercase tracking-wider text-muted font-medium">
        Update requests
      </div>
      <ul className="divide-y divide-subtle">
        {requests.slice(0, 8).map((r) => (
          <li key={r.id} className="px-3 py-2.5 flex items-start justify-between gap-2">
            <div className="min-w-0 text-xs">
              <div className="text-secondary">
                {Object.keys(r.changes).map((k) => FIELD_LABELS[k] ?? k).join(', ')}
              </div>
              <div className="text-muted">{r.created_at?.slice(0, 10)}</div>
            </div>
            <Chip variant={STATUS_CHIP[r.status] ?? 'neutral'}>
              {STATUS_LABEL[r.status] ?? r.status}
            </Chip>
          </li>
        ))}
      </ul>
    </section>
  );
}

function EditableBlock({
  title,
  fields,
  values,
  pendingFields,
  onSubmitted,
}: {
  title: string;
  fields: FieldDef[];
  values: SelfServiceProfile;
  pendingFields: Set<string>;
  onSubmitted: () => void;
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

  const anyPending = fields.some((f) => pendingFields.has(f.key));

  return (
    <section className="rounded-md border border-default bg-canvas">
      <div className="px-3 py-2 border-b border-subtle flex items-center justify-between">
        <span className="text-2xs uppercase tracking-wider text-muted font-medium">{title}</span>
        {!editing && (
          <button
            type="button"
            onClick={startEdit}
            className="inline-flex items-center gap-1 text-xs text-accent hover:underline"
          >
            <Pencil size={12} /> Edit
          </button>
        )}
      </div>

      {!editing ? (
        <dl className="divide-y divide-subtle">
          {fields.map((f) => (
            <div key={f.key} className="flex items-center justify-between px-3 py-2.5">
              <dt className="text-xs text-muted">{f.label}</dt>
              <dd className="text-sm text-right max-w-[60%] truncate">
                {get(f.key) || '—'}
              </dd>
            </div>
          ))}
          {anyPending && (
            <div className="px-3 py-2">
              <Chip variant="warning">Change pending HR review</Chip>
            </div>
          )}
        </dl>
      ) : (
        <div className="p-3 space-y-3">
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
          {error && <p className="text-xs text-danger">{error}</p>}
          <p className="text-2xs text-muted">
            Changes are reviewed by HR before they take effect.
          </p>
          <div className="flex justify-end gap-2">
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
              {mutation.isPending ? 'Submitting...' : 'Submit for review'}
            </Button>
          </div>
        </div>
      )}
    </section>
  );
}

function BankBlock({
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
    <section className="rounded-md border border-default bg-canvas">
      <div className="px-3 py-2 border-b border-subtle flex items-center justify-between">
        <span className="text-2xs uppercase tracking-wider text-muted font-medium">
          Bank account · HR + Finance approval
        </span>
        {!pending && (
          <button
            type="button"
            onClick={() => { setError(null); setOpen(true); }}
            className="text-xs text-accent hover:underline"
          >
            Request update
          </button>
        )}
      </div>
      <dl className="divide-y divide-subtle">
        <div className="flex items-center justify-between px-3 py-2.5">
          <dt className="text-xs text-muted">Bank</dt>
          <dd className="text-sm">{bankName || '—'}</dd>
        </div>
        <div className="flex items-center justify-between px-3 py-2.5">
          <dt className="text-xs text-muted">Account</dt>
          <dd className="text-sm font-mono tabular-nums">{accountLast4 ?? '—'}</dd>
        </div>
        {pending && (
          <div className="px-3 py-2">
            <Chip variant="info">Change awaiting HR + Finance</Chip>
          </div>
        )}
      </dl>

      <BottomSheet isOpen={open} onClose={() => setOpen(false)} title="Request Bank Account Update">
        <div className="space-y-3">
          <p className="text-xs text-muted">
            Bank changes affect payroll disbursement and require approval from
            both HR and Finance. Your current account stays in use until approved.
          </p>
          <Input
            label="Bank name"
            value={bank}
            onChange={(e) => setBank(e.target.value)}
            placeholder="e.g. BDO Unibank"
          />
          <Input
            label="New account number"
            value={account}
            onChange={(e) => setAccount(e.target.value)}
            placeholder="Account number"
          />
          {error && <p className="text-xs text-danger">{error}</p>}
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={mutation.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              onClick={() => mutation.mutate()}
              disabled={mutation.isPending}
              loading={mutation.isPending}
            >
              {mutation.isPending ? 'Submitting...' : 'Submit request'}
            </Button>
          </div>
        </div>
      </BottomSheet>
    </section>
  );
}
