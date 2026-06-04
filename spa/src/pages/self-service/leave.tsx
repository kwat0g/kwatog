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
import { BottomSheet } from '@/components/ui/BottomSheet';
import { Select } from '@/components/ui/Select';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
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

export default function SelfServiceLeavePage() {
  const queryClient = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const [sheetOpen, setSheetOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'leave'],
    queryFn: () =>
      client.get<{ data: any[] }>('/leaves/requests', {
        params: { per_page: 50, scope: 'self' },
      }).then((r) => r.data),
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
    () => Object.fromEntries((balances ?? []).map((b) => [b.leave_type_id, b])),
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
      setSheetOpen(false);
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

  return (
    <div>
      <PageHeader title="My Leave Requests" backTo="/self-service" backLabel="Dashboard" />
      <div className="px-5 py-4 space-y-4">
        <div className="flex items-center justify-end">
          <Button
            variant="primary"
            size="sm"
            icon={<Plus size={14} />}
            onClick={() => setSheetOpen(true)}
          >
            New request
          </Button>
        </div>

        {isLoading && (
          <div className="space-y-2">
            {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}
          </div>
        )}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Couldn't load leaves"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}
        {data && data.data.length === 0 && (
          <EmptyState
            icon="file-text"
            title="No leave requests yet"
            description="Tap New Request to file your first leave."
          />
        )}
        {data && data.data.length > 0 && (
          <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
            {data.data.map((r: any) => (
              <li key={r.id} className="px-3 py-2.5">
                <div className="flex justify-between items-center">
                  <div>
                    <div className="text-sm font-medium font-mono tabular-nums">
                      {r.leave_request_no ?? r.id}
                    </div>
                    <div className="text-xs text-muted">
                      {r.start_date} → {r.end_date} · {r.days} day{Number(r.days) !== 1 ? 's' : ''}
                    </div>
                    {r.leave_type?.name && (
                      <div className="text-xs text-subtle">{r.leave_type.name}</div>
                    )}
                  </div>
                  <Chip variant={STATUS_CHIP[r.status] ?? 'neutral'}>
                    {r.status?.replace(/_/g, ' ')}
                  </Chip>
                </div>
              </li>
            ))}
          </ul>
        )}

        <BottomSheet
          isOpen={sheetOpen}
          onClose={() => { reset(); setSheetOpen(false); }}
          title="File Leave Request"
        >
          <form onSubmit={handleSubmit((v) => file.mutate(v))} className="space-y-4">
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
                  <span>Balance: {selectedBalance.name}</span>
                  <span className="font-mono tabular-nums">
                    {selectedBalance.remaining} / {selectedBalance.total} days remaining
                  </span>
                </div>
                <div className="h-1.5 rounded-full bg-subtle overflow-hidden">
                  <div
                    className="h-full rounded-full bg-accent"
                    style={{
                      width: `${selectedBalance.total > 0
                        ? Math.min(100, (selectedBalance.remaining / selectedBalance.total) * 100)
                        : 0}%`,
                    }}
                  />
                </div>
              </div>
            )}

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
                onClick={() => { reset(); setSheetOpen(false); }}
                disabled={file.isPending}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                variant="primary"
                disabled={file.isPending}
                loading={file.isPending}
              >
                {file.isPending ? 'Submitting…' : 'Submit request'}
              </Button>
            </div>
          </form>
        </BottomSheet>
      </div>
    </div>
  );
}
