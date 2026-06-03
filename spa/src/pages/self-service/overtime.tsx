/**
 * Task SS1 — Self-service overtime requests.
 *
 * Mobile-first: pending + history lists and a bottom-sheet apply form with
 * hour quick-select chips, today's shift, and an estimated-pay preview.
 * Backend scopes everything to the session employee (never sends employee_id).
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { selfServiceApi } from '@/api/self-service';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { Textarea } from '@/components/ui/Textarea';
import { Input } from '@/components/ui/Input';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatPeso } from '@/lib/formatNumber';
import type { ApiValidationError } from '@/types';
import type {
  SelfServiceOvertimeRequest,
  OvertimeStatus,
  ApplyOvertimePayload,
} from '@/types/self-service';

const STATUS_CHIP: Record<OvertimeStatus, 'success' | 'warning' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
};

const HOUR_OPTIONS = [1, 1.5, 2, 3, 4];
const OT_PREMIUM = 1.25;

function todayIso(): string {
  // Local YYYY-MM-DD (avoids UTC off-by-one from toISOString()).
  const d = new Date();
  const off = d.getTimezoneOffset() * 60_000;
  return new Date(d.getTime() - off).toISOString().slice(0, 10);
}

export default function SelfServiceOvertimePage() {
  const queryClient = useQueryClient();
  const [sheetOpen, setSheetOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'overtime'],
    queryFn: () => selfServiceApi.overtime(),
  });

  return (
    <div>
      <PageHeader title="Overtime Requests" backTo="/self-service" backLabel="Dashboard" />
      <div className="px-5 py-4 space-y-4">
        <div className="flex items-center justify-end">
          <Button
            variant="primary"
            size="sm"
            icon={<Plus size={14} />}
            onClick={() => setSheetOpen(true)}
          >
            Apply for OT
          </Button>
        </div>

      {/* LOADING */}
      {isLoading && !data && (
        <div className="space-y-2">
          {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-16 rounded-md" />)}
        </div>
      )}

      {/* ERROR */}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Couldn't load overtime requests"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {/* DATA */}
      {data && (
        <>
          <Section title="Pending">
            {data.pending.length === 0 ? (
              <p className="text-xs text-muted px-1 py-2">No pending requests.</p>
            ) : (
              <RequestList rows={data.pending} />
            )}
          </Section>

          <Section title="History">
            {data.history.length === 0 ? (
              <p className="text-xs text-muted px-1 py-2">No past requests yet.</p>
            ) : (
              <RequestList rows={data.history} />
            )}
          </Section>
        </>
      )}

      <ApplyOvertimeSheet
        isOpen={sheetOpen}
        onClose={() => setSheetOpen(false)}
        shift={data?.todays_shift ?? null}
        hourlyRate={data?.hourly_rate ?? null}
        onApplied={() => {
          queryClient.invalidateQueries({ queryKey: ['self-service', 'overtime'] });
          setSheetOpen(false);
        }}
      />
      </div>{/* .px-5 py-4 */}
    </div>
  );
}

/* ───────────────────────── Sub-components ───────────────────────── */

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-2">
      <h2 className="text-2xs uppercase tracking-wider text-muted font-medium">{title}</h2>
      {children}
    </section>
  );
}

function RequestList({ rows }: { rows: SelfServiceOvertimeRequest[] }) {
  return (
    <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
      {rows.map((r) => (
        <li key={r.id} className="px-3 py-2.5">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="text-sm font-medium font-mono tabular-nums">
                {r.date ?? '—'} · {r.hours_requested}h OT
              </div>
              {r.reason && <div className="text-xs text-muted truncate">{r.reason}</div>}
              {r.status === 'rejected' && r.rejection_reason && (
                <div className="text-xs text-danger mt-0.5">Reason: {r.rejection_reason}</div>
              )}
            </div>
            <Chip variant={r.status ? STATUS_CHIP[r.status] : 'neutral'}>
              {r.status === 'pending' ? 'Pending approval' : r.status ?? '—'}
            </Chip>
          </div>
        </li>
      ))}
    </ul>
  );
}

function ApplyOvertimeSheet({
  isOpen,
  onClose,
  shift,
  hourlyRate,
  onApplied,
}: {
  isOpen: boolean;
  onClose: () => void;
  shift: { name: string; time_in: string; time_out: string } | null;
  hourlyRate: string | null;
  onApplied: () => void;
}) {
  const [date, setDate] = useState(todayIso());
  const [hours, setHours] = useState<number>(2);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const estimate = useMemo(() => {
    const rate = Number(hourlyRate ?? 0);
    if (!rate) return null;
    return rate * hours * OT_PREMIUM;
  }, [hourlyRate, hours]);

  const mutation = useMutation({
    mutationFn: (payload: ApplyOvertimePayload) => selfServiceApi.applyOvertime(payload),
    onSuccess: () => {
      toast.success('Overtime request submitted for approval.');
      setReason('');
      setHours(2);
      setDate(todayIso());
      onApplied();
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      const data = err.response?.data;
      if (err.response?.status === 422 && data?.errors) {
        setError(Object.values(data.errors)[0]?.[0] ?? 'Please check your input.');
      } else {
        toast.error('Failed to submit overtime request.');
      }
    },
  });

  const handleSubmit = () => {
    setError(null);
    if (reason.trim().length < 5) {
      setError('Please provide a reason (at least 5 characters).');
      return;
    }
    mutation.mutate({ date, hours_requested: hours, reason: reason.trim() });
  };

  return (
    <BottomSheet isOpen={isOpen} onClose={onClose} title="Apply for Overtime">
      <div className="space-y-4">
        <Input
          label="Date"
          type="date"
          value={date}
          min={todayIso()}
          onChange={(e) => setDate(e.target.value)}
        />

        <div>
          <label className="text-xs text-muted font-medium">Hours</label>
          <div className="flex flex-wrap gap-2 mt-1">
            {HOUR_OPTIONS.map((h) => (
              <button
                key={h}
                type="button"
                onClick={() => setHours(h)}
                className={`h-9 min-w-[3rem] px-3 rounded-md border text-sm font-mono tabular-nums ${
                  hours === h
                    ? 'border-accent bg-accent text-accent-fg font-medium'
                    : 'border-default bg-canvas text-primary hover:bg-elevated'
                }`}
                aria-pressed={hours === h}
              >
                {h.toFixed(1)}
              </button>
            ))}
          </div>
          <p className="text-2xs text-muted mt-1">Maximum 4 hours per day.</p>
        </div>

        <Textarea
          label="Reason"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          placeholder="Why is overtime needed? (sent to your Dept Head)"
          error={error ?? undefined}
        />

        {/* Context: shift + estimated pay */}
        <div className="rounded-md border border-default bg-surface p-3 space-y-1 text-xs">
          {shift ? (
            <div className="flex justify-between">
              <span className="text-muted">Your shift</span>
              <span className="font-mono tabular-nums">
                {shift.name} · {shift.time_in?.slice(0, 5)}–{shift.time_out?.slice(0, 5)}
              </span>
            </div>
          ) : (
            <div className="text-muted">No shift assigned for today.</div>
          )}
          {estimate !== null && (
            <div className="flex justify-between">
              <span className="text-muted">Estimated pay</span>
              <span className="font-mono tabular-nums text-primary">
                {formatPeso(hourlyRate ?? 0)}/hr × {hours.toFixed(1)} × {OT_PREMIUM} ={' '}
                <span className="font-medium">{formatPeso(estimate)}</span>
              </span>
            </div>
          )}
          <p className="text-2xs text-muted pt-1">
            Estimate only — final OT pay is computed at payroll.
          </p>
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={handleSubmit}
            disabled={mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Submitting...' : 'Submit for Approval'}
          </Button>
        </div>
      </div>
    </BottomSheet>
  );
}
