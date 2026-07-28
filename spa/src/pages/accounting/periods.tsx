import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Lock, LockOpen } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import {
  accountingPeriodsApi,
  type AccountingPeriod,
  type AccountingPeriodStatus,
} from '@/api/accounting/periods';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const statusVariant = (s: AccountingPeriodStatus): ChipVariant =>
  s === 'closed' ? 'info' : s === 'reopened' ? 'warning' : 'neutral';

export default function AccountingPeriodsPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('accounting.periods.manage');

  const [closeTarget, setCloseTarget] = useState<AccountingPeriod | null>(null);
  const [reopenTarget, setReopenTarget] = useState<AccountingPeriod | null>(null);

  const periodsQ = useQuery({
    queryKey: ['accounting', 'periods'],
    queryFn: () => accountingPeriodsApi.list({ per_page: 36 }),
    placeholderData: (prev) => prev,
  });

  const closeMut = useMutation({
    mutationFn: (p: AccountingPeriod) => accountingPeriodsApi.close(p.year, p.month),
    onSuccess: () => { toast.success('Period closed.'); setCloseTarget(null); qc.invalidateQueries({ queryKey: ['accounting', 'periods'] }); },
    onError: (e: AxiosError<{ message?: string }>) => { setCloseTarget(null); toast.error(e.response?.data?.message ?? 'Failed to close period.'); },
  });

  const reopenMut = useMutation({
    mutationFn: (v: { p: AccountingPeriod; reason: string }) => accountingPeriodsApi.reopen(v.p.year, v.p.month, v.reason),
    onSuccess: () => { toast.success('Period reopened.'); setReopenTarget(null); qc.invalidateQueries({ queryKey: ['accounting', 'periods'] }); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to reopen period.'),
  });

  const periods = periodsQ.data?.data ?? [];

  return (
    <div>
      <PageHeader
        title="Accounting Periods"
        subtitle="Close a month to lock its entries; reopening requires a reason and is recorded."
        breadcrumbs={[{ label: 'Accounting' }, { label: 'Periods' }]}
      />

      <div className="px-5 py-4">
        <Panel title="Periods">
          {periodsQ.isLoading && !periodsQ.data && <SkeletonTable columns={6} rows={8} />}
          {periodsQ.isError && (
            <EmptyState icon="alert-circle" title="Failed to load periods"
              action={<Button variant="secondary" onClick={() => periodsQ.refetch()}>Retry</Button>} />
          )}
          {periodsQ.data && periods.length === 0 && (
            <EmptyState icon="inbox" title="No accounting periods" description="Periods appear here as entries are posted." />
          )}
          {periods.length > 0 && (
            <div className="border border-default rounded-md overflow-hidden">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Period</Th>
                    <Th>Status</Th>
                    <Th>Closed</Th>
                    <Th>Reopened</Th>
                    <Th>Reopen reason</Th>
                    <Th align="right" />
                  </tr>
                </thead>
                <tbody>
                  {periods.map((p) => (
                    <tr key={p.id} className={trCls}>
                      <Td mono>{MONTHS[p.month]} {p.year}</Td>
                      <Td><Chip variant={statusVariant(p.status)}>{p.status_label}</Chip></Td>
                      <Td className="text-xs text-muted">
                        {p.closed_at ? <>{formatDate(p.closed_at)}{p.closed_by?.name ? ` · ${p.closed_by.name}` : ''}</> : '—'}
                      </Td>
                      <Td className="text-xs text-muted">
                        {p.reopened_at ? <>{formatDate(p.reopened_at)}{p.reopened_by?.name ? ` · ${p.reopened_by.name}` : ''}</> : '—'}
                      </Td>
                      <Td className="text-xs text-muted max-w-[220px] truncate">{p.reopen_reason ?? '—'}</Td>
                      <Td align="right">
                        {canManage && p.status !== 'closed' && (
                          <Button variant="secondary" size="sm" icon={<Lock size={13} />} onClick={() => setCloseTarget(p)}>
                            Close
                          </Button>
                        )}
                        {canManage && p.status === 'closed' && (
                          <Button variant="secondary" size="sm" icon={<LockOpen size={13} />} onClick={() => setReopenTarget(p)}>
                            Reopen
                          </Button>
                        )}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>

      <ConfirmDialog
        isOpen={closeTarget !== null}
        onClose={() => setCloseTarget(null)}
        onConfirm={() => { if (closeTarget) closeMut.mutate(closeTarget); }}
        title={closeTarget ? `Close ${MONTHS[closeTarget.month]} ${closeTarget.year}?` : 'Close period?'}
        description="Entries dated in this month will be locked. You can reopen later with a reason."
        variant="warning"
        confirmLabel="Close period"
        pending={closeMut.isPending}
      />

      <ReopenModal
        period={reopenTarget}
        onClose={() => setReopenTarget(null)}
        onConfirm={(reason) => reopenTarget && reopenMut.mutate({ p: reopenTarget, reason })}
        pending={reopenMut.isPending}
      />
    </div>
  );
}

function ReopenModal({
  period, onClose, onConfirm, pending,
}: {
  period: AccountingPeriod | null;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  pending: boolean;
}) {
  const [reason, setReason] = useState('');
  const tooShort = reason.trim().length < 3;

  return (
    <Modal isOpen={period !== null} onClose={onClose} size="md"
      title={period ? `Reopen ${MONTHS[period.month]} ${period.year}` : 'Reopen period'}>
      <div className="space-y-3 py-3">
        <p className="text-xs text-muted">
          Reopening a closed month is audited. State why — this is stored on the period and shown to reviewers.
        </p>
        <div>
          <label className="block text-xs font-medium text-primary mb-1">Reason <span className="text-danger-fg">*</span></label>
          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            placeholder="e.g. Backdated supplier invoice received after close."
            className="block w-full rounded-md border border-default bg-canvas px-3 py-2 text-sm text-primary placeholder:text-muted focus:border-accent focus:outline-none"
          />
          <p className="mt-1 text-2xs text-muted">Minimum 3 characters.</p>
        </div>
      </div>
      <div className="flex justify-end gap-2 pt-3 border-t border-default">
        <Button variant="secondary" onClick={onClose} disabled={pending}>Cancel</Button>
        <Button variant="primary" onClick={() => onConfirm(reason.trim())} disabled={tooShort || pending} loading={pending}>
          {pending ? 'Reopening…' : 'Reopen period'}
        </Button>
      </div>
    </Modal>
  );
}
