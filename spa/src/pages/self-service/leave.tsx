/* eslint-disable @typescript-eslint/no-explicit-any */
/** Sprint 8 — Task 74 + SS-LF. Self-service: my leave requests. In-portal filing. */
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { client } from '@/api/client';
import { selfServiceApi } from '@/api/self-service';
import { useAuthStore } from '@/stores/authStore';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate } from '@/lib/formatDate';
import type { ApiValidationError } from '@/types';
import type { SelfServiceLeaveType, SelfServiceLeaveBalanceSelf } from '@/types/self-service';

const STATUS_CHIP: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = {
  pending: 'warning', pending_dept: 'warning', pending_hr: 'info',
  approved: 'success', rejected: 'danger', cancelled: 'neutral',
};

const schema = z.object({
  leave_type_id: z.string().min(1, 'Select a leave type'),
  start_date: z.string().min(1, 'Required'),
  end_date: z.string().min(1, 'Required'),
  reason: z.string().max(2000).optional().or(z.literal('')),
}).refine((d) => d.end_date >= d.start_date, {
  message: 'End date must be on or after start date',
  path: ['end_date'],
});

type FormValues = z.infer<typeof schema>;

const columns: Column<any>[] = [
  {
    key: 'leave_request_no',
    header: 'Request no',
    cell: (r) => <NumCell>{r.leave_request_no ?? r.id}</NumCell>,
  },
  {
    key: 'type',
    header: 'Type',
    cell: (r) => r.leave_type?.name ?? '—',
  },
  {
    key: 'dates',
    header: 'Dates',
    cell: (r) => (
      <NumCell>
        {formatDate(r.start_date)} → {formatDate(r.end_date)}
      </NumCell>
    ),
  },
  {
    key: 'days',
    header: 'Days',
    align: 'right',
    cell: (r) => <NumCell>{r.days}</NumCell>,
  },
  {
    key: 'reason',
    header: 'Reason',
    cell: (r) => (
      <span className="text-muted block max-w-[280px] truncate">{r.reason || '—'}</span>
    ),
  },
  {
    key: 'status',
    header: 'Status',
    cell: (r) => (
      <Chip variant={STATUS_CHIP[r.status] ?? 'neutral'}>
        {r.status?.replace(/_/g, ' ')}
      </Chip>
    ),
  },
];

export default function SelfServiceLeavePage() {
  const queryClient = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const hasEmployeeLink = Boolean(user?.employee?.id);
  const [modalOpen, setModalOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'leave'],
    queryFn: () =>
      client.get<{ data: any[] }>('/leaves/requests', {
        params: { per_page: 50, scope: 'self' },
      }).then((r) => r.data),
    placeholderData: (prev) => prev,
  });

  const { data: types } = useQuery({
    queryKey: ['leave-types-self'],
    queryFn: () => selfServiceApi.leaveTypes(),
    staleTime: 5 * 60_000,
  });

  const { data: balances } = useQuery({
    queryKey: ['self-service', 'leave-balances'],
    queryFn: () => selfServiceApi.leaveBalancesMe(),
  });

  const balanceMap = useMemo<Record<string, SelfServiceLeaveBalanceSelf>>(
    () => Object.fromEntries((balances ?? []).map((b) => [b.leave_type.id, b])),
    [balances],
  );

  const {
    register,
    handleSubmit,
    watch,
    setError,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { leave_type_id: '', start_date: '', end_date: '', reason: '' },
  });

  const selectedTypeId = watch('leave_type_id');
  const selectedBalance = selectedTypeId ? balanceMap[selectedTypeId] : null;
  const selectedType = selectedTypeId
    ? (types ?? []).find((t) => t.id === selectedTypeId) ?? null
    : null;

  const file = useMutation({
    mutationFn: (v: FormValues) =>
      selfServiceApi.fileLeaveSelf({
        employee_id: user?.employee?.id ?? '',
        leave_type_id: v.leave_type_id,
        start_date: v.start_date,
        end_date: v.end_date,
        reason: v.reason || undefined,
      }),
    onSuccess: () => {
      toast.success('Leave request submitted for approval.');
      queryClient.invalidateQueries({ queryKey: ['self-service', 'leave'] });
      queryClient.invalidateQueries({ queryKey: ['self-service', 'leave-balances'] });
      reset();
      setModalOpen(false);
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      const errs = err.response?.data?.errors;
      if (errs) {
        (Object.entries(errs) as [keyof FormValues, string[]][]).forEach(([field, msgs]) => {
          setError(field, { message: msgs[0] });
        });
      } else {
        toast.error('Failed to submit leave request.');
      }
    },
  });

  const pendingCount = (data?.data ?? []).filter(
    (r: any) => r.status === 'pending' || r.status === 'pending_dept' || r.status === 'pending_hr',
  ).length;

  return (
    <div>
      <PageHeader
        title="My Leave Requests"
        subtitle={data ? `${data.data.length} total · ${pendingCount} awaiting approval` : undefined}
        actions={!data || data.data.length > 0 ? (
          <Button
            variant="primary"
            size="sm"
            icon={<Plus size={14} />}
            onClick={() => setModalOpen(true)}
          >
            New request
          </Button>
        ) : undefined}
      />
      <div className="px-5 py-4">
        {isLoading && !data && <SkeletonTable columns={6} rows={6} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Couldn't load leaves"
            description="An error occurred while loading your requests. Please try again."
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {data && data.data.length === 0 && (
          <EmptyState
            icon="file-text"
            title="No leave requests yet"
            description="File your first leave request to see it here."
            action={
              <Button variant="primary" icon={<Plus size={14} />} onClick={() => setModalOpen(true)}>
                New request
              </Button>
            }
          />
        )}

        {data && data.data.length > 0 && (
          <DataTable columns={columns} data={data.data} stickyHeader={false} />
        )}

        <Modal
          isOpen={modalOpen}
          onClose={() => { reset(); setModalOpen(false); }}
          title="File Leave Request"
        >
          <form onSubmit={handleSubmit((v) => file.mutate(v))} className="space-y-4 py-4">
            {!hasEmployeeLink && (
              <div className="rounded-md border border-default bg-subtle px-3 py-2 text-xs text-muted">
                Your account is not linked to an employee record. Contact HR to file leave.
              </div>
            )}
            <Select
              label="Leave type"
              {...register('leave_type_id')}
              error={errors.leave_type_id?.message}
              required
            >
              <option value="">— Select —</option>
              {(types ?? []).map((t: SelfServiceLeaveType) => (
                <option key={t.id} value={t.id}>{t.name}</option>
              ))}
            </Select>

            {selectedBalance && (
              <div className="rounded-md border border-default bg-surface px-3 py-2 text-xs">
                <div className="flex justify-between text-muted mb-1">
                  <span>Balance: {selectedBalance.leave_type.name}</span>
                  <span className="font-mono tabular-nums">
                    {selectedBalance.remaining} / {selectedBalance.total_credits} days remaining
                  </span>
                </div>
                <div className="h-1.5 rounded-full bg-subtle overflow-hidden">
                  <div
                    className="h-full rounded-full bg-accent"
                    style={{
                      width: `${Number(selectedBalance.total_credits) > 0
                        ? Math.min(100, (Number(selectedBalance.remaining) / Number(selectedBalance.total_credits)) * 100)
                        : 0}%`,
                    }}
                  />
                </div>
              </div>
            )}

            {selectedType?.requires_document && (
              <div className="rounded-md border border-warning bg-warning-bg px-3 py-2 text-xs text-warning-fg">
                This leave type requires a supporting document. Submit it to HR separately after filing.
              </div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <Input
                label="Start date"
                type="date"
                {...register('start_date')}
                error={errors.start_date?.message}
                required
              />
              <Input
                label="End date"
                type="date"
                {...register('end_date')}
                error={errors.end_date?.message}
                required
              />
            </div>
            <Textarea
              label="Reason (optional)"
              rows={3}
              {...register('reason')}
              error={errors.reason?.message}
            />
            <div className="flex justify-end gap-2 pt-2 border-t border-default">
              <Button
                type="button"
                variant="secondary"
                onClick={() => { reset(); setModalOpen(false); }}
                disabled={file.isPending}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                variant="primary"
                disabled={file.isPending || !hasEmployeeLink}
                loading={file.isPending}
              >
                {file.isPending ? 'Submitting…' : 'Submit request'}
              </Button>
            </div>
          </form>
        </Modal>
      </div>
    </div>
  );
}
