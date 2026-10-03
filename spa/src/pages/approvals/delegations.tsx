import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { approvalDelegationsApi, type ApprovalDelegation } from '@/api/approval-delegations';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { Select } from '@/components/ui/Select';
import { formatDate, localIsoDate } from '@/lib/formatDate';
import { useAuthStore } from '@/stores/authStore';

function statusOf(delegation: ApprovalDelegation, today: string): { label: string; variant: 'success' | 'warning' | 'danger' | 'neutral' } {
  if (!delegation.is_active) return { label: 'Revoked', variant: 'neutral' };
  if (delegation.delegator && !delegation.delegator.is_active) return { label: 'Delegator inactive', variant: 'danger' };
  if (!delegation.delegate?.is_active) return { label: 'Delegate inactive', variant: 'danger' };
  if (!delegation.role_is_current) return { label: 'Role changed', variant: 'danger' };
  if (delegation.ends_at < today) return { label: 'Expired', variant: 'neutral' };
  if (delegation.starts_at > today) return { label: 'Scheduled', variant: 'warning' };
  return { label: 'Active', variant: 'success' };
}

function roleLabel(roleSlug: string | null): string {
  return roleSlug ? roleSlug.replaceAll('_', ' ') : 'All current roles';
}

function serverMessage(error: unknown, fallback: string): string {
  if (error instanceof AxiosError) {
    const response = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    return response?.message ?? Object.values(response?.errors ?? {})[0]?.[0] ?? fallback;
  }
  return fallback;
}

export default function ApprovalDelegationsPage() {
  const user = useAuthStore((state) => state.user);
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [delegateId, setDelegateId] = useState('');
  const [startsAt, setStartsAt] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [reason, setReason] = useState('');
  const [formError, setFormError] = useState<string | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<ApprovalDelegation | null>(null);

  const delegationsQuery = useQuery({
    queryKey: ['approval-delegations'],
    queryFn: approvalDelegationsApi.list,
  });
  const normalizedSearch = search.trim();
  const candidatesQuery = useQuery({
    queryKey: ['approval-delegation-candidates', normalizedSearch],
    queryFn: () => approvalDelegationsApi.candidates(normalizedSearch),
    enabled: normalizedSearch.length >= 2,
    retry: false,
  });

  const createMutation = useMutation({
    mutationFn: (data: Parameters<typeof approvalDelegationsApi.create>[0]) => approvalDelegationsApi.create(data),
    onSuccess: async () => {
      toast.success('Approval cover set up.');
      setSearch('');
      setDelegateId('');
      setStartsAt('');
      setEndsAt('');
      setReason('');
      setFormError(null);
      await queryClient.invalidateQueries({ queryKey: ['approval-delegations'] });
    },
    onError: (error) => setFormError(serverMessage(error, 'Could not set up approval cover. Try again.')),
  });
  const revokeMutation = useMutation({
    mutationFn: (id: string) => approvalDelegationsApi.revoke(id),
    onSuccess: async () => {
      toast.success('Approval cover revoked.');
      setPendingRevoke(null);
      await queryClient.invalidateQueries({ queryKey: ['approval-delegations'] });
    },
    onError: (error) => toast.error(serverMessage(error, 'Could not revoke approval cover. Try again.')),
  });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    if (!user?.role?.slug) {
      setFormError('Your account has no assigned role. Contact an administrator before setting up approval cover.');
      return;
    }
    if (endsAt < startsAt) {
      setFormError('The end date must be on or after the start date.');
      return;
    }

    createMutation.mutate({
      delegate_user_id: delegateId,
      role_slug: user.role.slug,
      starts_at: startsAt,
      ends_at: endsAt,
      ...(reason.trim() ? { reason: reason.trim() } : {}),
    });
  };

  const today = localIsoDate();
  const delegations = delegationsQuery.data ?? [];

  return (
    <div>
      <PageHeader
        title="Approval cover"
        subtitle="Choose an active colleague to cover your approval role for a date range."
      />

      <div className="mx-auto max-w-5xl space-y-6 p-5">
        <div className="rounded-md border border-info/30 bg-info-bg px-4 py-3 text-sm text-info-fg">
          Cover applies only to approvals in your current role. You still cannot approve a record you submitted.
          If a delegate account is deactivated, their cover stops immediately.
        </div>

        <section aria-labelledby="approval-cover-form-title" className="rounded-lg border border-default bg-canvas p-4 sm:p-5">
          <h2 id="approval-cover-form-title" className="text-base font-semibold text-primary">Set up approval cover</h2>
          <p className="mt-1 text-sm text-muted">A role change ends this exact-role delegation. Revoke and create a new one to change the person or dates.</p>

          <form className="mt-4 space-y-4" onSubmit={submit}>
            <div className="grid gap-4 md:grid-cols-2">
              <Input
                label="Search active colleagues"
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setDelegateId('');
                  setFormError(null);
                }}
                placeholder="Name or email, at least 2 characters"
                autoComplete="off"
              />
              <Select
                label="Delegate"
                value={delegateId}
                onChange={(event) => {
                  setDelegateId(event.target.value);
                  setFormError(null);
                }}
                required
                disabled={normalizedSearch.length < 2 || candidatesQuery.isLoading || candidatesQuery.isError}
                helper={normalizedSearch.length < 2
                  ? 'Enter at least 2 characters to search.'
                  : candidatesQuery.isLoading
                    ? 'Searching active colleagues…'
                    : 'Only active internal users can receive approval cover.'}
              >
                <option value="">Choose a colleague</option>
                {(candidatesQuery.data ?? []).map((candidate) => (
                  <option key={candidate.id} value={candidate.id}>
                    {candidate.name}{candidate.role ? ` — ${candidate.role}` : ''}
                  </option>
                ))}
              </Select>
            </div>
            {candidatesQuery.isError && (
              <p role="alert" className="text-sm text-danger-fg">
                Could not search active colleagues. <button type="button" className="underline" onClick={() => void candidatesQuery.refetch()}>Try again</button>
              </p>
            )}
            {normalizedSearch.length >= 2 && candidatesQuery.isSuccess && (candidatesQuery.data?.length ?? 0) === 0 && (
              <p role="status" className="text-sm text-muted">No active colleague matched that search.</p>
            )}

            <div className="grid gap-4 md:grid-cols-2">
              <Input
                label="Start date"
                type="date"
                value={startsAt}
                onChange={(event) => { setStartsAt(event.target.value); setFormError(null); }}
                required
              />
              <Input
                label="End date"
                type="date"
                value={endsAt}
                onChange={(event) => { setEndsAt(event.target.value); setFormError(null); }}
                required
              />
            </div>
            <Input
              label="Reason (optional)"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              maxLength={500}
              helper={`${reason.length}/500 characters`}
            />
            {formError && <p role="alert" className="text-sm text-danger-fg">{formError}</p>}
            <div className="flex flex-wrap items-center gap-3">
              <Button type="submit" variant="primary" loading={createMutation.isPending}>
                Set up approval cover
              </Button>
              <span className="text-xs text-muted">Current role: {roleLabel(user?.role?.slug ?? null)}</span>
            </div>
          </form>
        </section>

        <section aria-labelledby="approval-cover-list-title" className="space-y-3">
          <div className="flex items-center justify-between gap-3">
            <h2 id="approval-cover-list-title" className="text-base font-semibold text-primary">Current and past cover</h2>
            <Button variant="secondary" size="sm" onClick={() => void delegationsQuery.refetch()} disabled={delegationsQuery.isFetching}>
              {delegationsQuery.isFetching ? 'Refreshing…' : 'Refresh'}
            </Button>
          </div>

          {delegationsQuery.isLoading && (
            <div aria-label="Loading approval cover" className="space-y-2">
              {[0, 1].map((row) => <div key={row} className="h-20 rounded-md bg-elevated animate-pulse" />)}
            </div>
          )}
          {delegationsQuery.isError && (
            <EmptyState
              icon="alert-circle"
              title="Could not load approval cover"
              description="Your delegation list is unavailable. Try again."
              action={<Button variant="secondary" onClick={() => void delegationsQuery.refetch()}>Retry</Button>}
            />
          )}
          {delegationsQuery.isSuccess && delegations.length === 0 && (
            <EmptyState
              icon="users"
              title="No approval cover set up"
              description="Delegations you create or receive will appear here."
            />
          )}
          {delegationsQuery.isSuccess && delegations.length > 0 && (
            <div className="space-y-2">
              {delegations.map((delegation) => {
                const outgoing = delegation.delegator?.id === user?.id;
                const otherPerson = outgoing ? delegation.delegate : delegation.delegator;
                const status = statusOf(delegation, today);
                return (
                  <article
                    key={delegation.id}
                    aria-label={`Approval cover ${outgoing ? 'to' : 'from'} ${otherPerson?.name ?? 'unknown user'}`}
                    className="flex flex-col gap-3 rounded-md border border-default bg-canvas p-4 sm:flex-row sm:items-start sm:justify-between"
                  >
                    <div className="min-w-0 space-y-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-medium text-primary">
                          {outgoing ? `Cover for ${otherPerson?.name ?? 'unknown user'}` : `Cover from ${otherPerson?.name ?? 'unknown user'}`}
                        </h3>
                        <Chip variant={status.variant}>{status.label}</Chip>
                      </div>
                      <p className="text-sm text-secondary">
                        {roleLabel(delegation.role_slug)} · {formatDate(delegation.starts_at)} – {formatDate(delegation.ends_at)}
                      </p>
                      {delegation.reason && <p className="text-sm text-muted">{delegation.reason}</p>}
                      {delegation.is_active && !delegation.delegate?.is_active && (
                        <p className="text-sm text-danger-fg">This delegate is inactive and cannot act. Revoke this cover or assign another active colleague.</p>
                      )}
                      {delegation.is_active && delegation.delegator && !delegation.delegator.is_active && (
                        <p className="text-sm text-danger-fg">The delegator is inactive, so this cover cannot be used. An administrator must reactivate the delegator or revoke the cover.</p>
                      )}
                      {delegation.is_active && delegation.delegator?.is_active && delegation.role_is_current === false && (
                        <p className="text-sm text-danger-fg">
                          This cover no longer works because the delegator changed roles. {delegation.can_revoke
                            ? 'Revoke this cover and set up a new one for the current role.'
                            : 'Ask the delegator or an administrator to revoke and reassign this cover.'}
                        </p>
                      )}
                    </div>
                    {delegation.can_revoke && delegation.is_active && (
                      <Button variant="danger" size="sm" onClick={() => setPendingRevoke(delegation)}>
                        Revoke
                      </Button>
                    )}
                  </article>
                );
              })}
            </div>
          )}
        </section>
      </div>

      <ConfirmDialog
        isOpen={pendingRevoke !== null}
        onClose={() => setPendingRevoke(null)}
        onConfirm={async () => {
          if (pendingRevoke) await revokeMutation.mutateAsync(pendingRevoke.id);
        }}
        title="Revoke approval cover?"
        description={<>This ends the cover for <span className="font-medium">{pendingRevoke?.delegate?.name ?? 'this colleague'}</span> immediately.</>}
        confirmLabel="Revoke cover"
        variant="danger"
        pending={revokeMutation.isPending}
      />
    </div>
  );
}
