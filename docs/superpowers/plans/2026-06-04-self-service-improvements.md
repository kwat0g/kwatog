# Self-Service Improvements & Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve the employee self-service portal with 5 targeted enhancements: in-portal leave filing (remove redirect to HR module), leave balance progress bars, DTR month picker, loan amortization preview, and a "Cancel Request" action for pending OT.

**Architecture:** All changes are frontend-only or thin backend additions to existing endpoints. No schema migrations needed. Each task is independent and self-contained.

**Tech Stack:** React 18, TypeScript, TanStack Query, React Hook Form + Zod, Axios, Laravel 11 (for backend additions)

---

## Task 1: In-Portal Leave Filing (remove redirect to HR module)

The current `leave.tsx` has a "New request" button that redirects to `/hr/leaves/create` — outside self-service. Employees must navigate away to file a leave. Fix: inline a BottomSheet form in `leave.tsx` that POSTs to `POST /leaves/requests` directly. Also show leave type balance before they pick.

**Files:**
- Modify: `spa/src/pages/self-service/leave.tsx`
- Modify: `spa/src/api/self-service.ts` (add `leaveTypes`, `leaveBalancesMe`, `fileLeaveSelf`)
- Modify: `spa/src/types/self-service.ts` (add `SelfServiceLeaveType`, `SelfServiceLeaveBalanceSelf`)

---

- [ ] **Step 1: Add API methods and types**

In `spa/src/types/self-service.ts`, append at the end:

```typescript
// ─── Leave filing (Task SS-LF) ─────────────────────────────────────
export interface SelfServiceLeaveType {
  id: string;
  code: string;
  name: string;
  requires_document: boolean;
}

export interface SelfServiceLeaveBalanceSelf {
  leave_type_id: string;
  code: string;
  name: string;
  total: number;
  used: number;
  remaining: number;
}

export interface FileLeavePayload {
  employee_id: string;  // own hash_id — backend validates it matches session
  leave_type_id: string;
  start_date: string;
  end_date: string;
  reason?: string;
}
```

- [ ] **Step 2: Add API calls**

In `spa/src/api/self-service.ts`, add these methods inside the `selfServiceApi` object:

```typescript
  // ─── Leave filing (Task SS-LF) ──────────────────────────────────
  leaveTypes: () =>
    client.get<{ data: SelfServiceLeaveType[] }>('/leaves/types').then((r) => r.data.data),

  leaveBalancesMe: () =>
    client
      .get<{ data: SelfServiceLeaveBalanceSelf[] }>('/leaves/balances/me')
      .then((r) => r.data.data),

  fileLeaveSelf: (payload: FileLeavePayload) =>
    client
      .post<{ message: string; data: { id: string } }>('/leaves/requests', payload)
      .then((r) => r.data),
```

Also import the new types at the top of the file:
```typescript
import type {
  // ... existing imports ...
  SelfServiceLeaveType,
  SelfServiceLeaveBalanceSelf,
  FileLeavePayload,
} from '@/types/self-service';
```

- [ ] **Step 3: Rewrite leave.tsx with inline BottomSheet form**

Replace the entire content of `spa/src/pages/self-service/leave.tsx`:

```tsx
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
        employee_id: user?.employee_id ?? '',
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
```

Note: The `client` import is missing in the new file — add it:
```typescript
import { client } from '@/api/client';
```

- [ ] **Step 4: Verify types compile**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | grep "self-service\|leave"
```

Expected: no errors related to `self-service/leave.tsx`.

- [ ] **Step 5: Commit**

```bash
git add spa/src/pages/self-service/leave.tsx spa/src/api/self-service.ts spa/src/types/self-service.ts
git commit -m "feat: self-service — inline leave filing with balance preview"
```

---

## Task 2: Leave Balance Progress Bars on Self-Service Home

The home dashboard shows KPI tiles from `GET /api/v1/dashboards/employee`. The `leave_balances` data exists on the `SelfServiceHome` interface (`leave_balances: SelfServiceLeaveBalance[]`). Display them as a progress bar section below the KPI tiles.

**Files:**
- Modify: `spa/src/pages/self-service/index.tsx`

---

- [ ] **Step 1: Add LeaveBalances section component**

In `spa/src/pages/self-service/index.tsx`, add this component after the `QuickAction` component at the bottom:

```tsx
function LeaveBalances({ balances }: { balances: Array<{ code: string; name: string; total: number; used: number; remaining: number }> }) {
  if (!balances.length) return null;
  return (
    <section aria-label="Leave balances">
      <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
        Leave balances
      </div>
      <div className="rounded-md border border-default bg-canvas divide-y divide-subtle">
        {balances.map((b) => {
          const pct = b.total > 0 ? Math.min(100, (b.remaining / b.total) * 100) : 0;
          return (
            <div key={b.code} className="px-3 py-2.5">
              <div className="flex items-baseline justify-between mb-1.5">
                <span className="text-sm">{b.name}</span>
                <span className="text-xs font-mono tabular-nums text-muted">
                  {b.remaining} / {b.total} days
                </span>
              </div>
              <div className="h-1.5 rounded-full bg-subtle overflow-hidden" aria-hidden="true">
                <div
                  className={`h-full rounded-full transition-[width] duration-500 ${
                    pct <= 20 ? 'bg-danger' : pct <= 50 ? 'bg-amber-500' : 'bg-accent'
                  }`}
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}
```

- [ ] **Step 2: Wire it into SelfServiceContent**

In the `/* ─── DATA ─── */` return block, after the `{/* Quick actions */}` section (before the closing `</div>`), add:

```tsx
      {/* Leave balances */}
      {raw.leave_balances && raw.leave_balances.length > 0 && (
        <LeaveBalances balances={raw.leave_balances} />
      )}
```

The full `return` block inside `SelfServiceContent` should now end:

```tsx
      {/* Leave balances */}
      {raw.leave_balances && raw.leave_balances.length > 0 && (
        <LeaveBalances balances={raw.leave_balances} />
      )}

    </div>
  );
```

- [ ] **Step 3: Update the EmployeeDashboardData interface**

In `index.tsx`, the `EmployeeDashboardData` interface is missing `leave_balances`. Update it:

```tsx
interface EmployeeDashboardData {
  kpis: EmployeeKpi[];
  leave_balances: Array<{ code: string; name: string; total: number; used: number; remaining: number }>;
  panels: {
    latest_payslip: LatestPayslip | null;
    next_holiday: NextHoliday | null;
    notice?: string;
  };
}
```

- [ ] **Step 4: Verify types**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | grep "self-service/index"
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add spa/src/pages/self-service/index.tsx
git commit -m "feat: self-service home — leave balance progress bars"
```

---

## Task 3: DTR Month Picker

`dtr.tsx` currently hardcodes to the current month (no params to the attendance API). Add a month picker so employees can browse previous months' attendance. The attendance API supports `from` and `to` date params (verified in `AttendanceService::list()`).

**Files:**
- Modify: `spa/src/pages/self-service/dtr.tsx`

---

- [ ] **Step 1: Verify attendance API date params work**

```bash
grep -n "'from'\|'to'\|from.*date\|to.*date" /home/kwat0g/Desktop/kwatog/api/app/Modules/Attendance/Services/AttendanceService.php
```

Expected: lines ~39-40 showing `$q->where('date', '>=', $filters['from'])` and `$q->where('date', '<=', $filters['to'])`.

- [ ] **Step 2: Rewrite dtr.tsx with month picker**

Replace the entire content of `spa/src/pages/self-service/dtr.tsx`:

```tsx
/** Sprint 8 — Task 74 + Sprint P5 + SS-DTR. DTR with month picker. */
import { useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { PageHeader } from '@/components/layout/PageHeader';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';

interface AttendanceRow {
  id: string;
  date: string;
  time_in: string | null;
  time_out: string | null;
  regular_hours: string | number | null;
  overtime_hours?: string | number | null;
  status?: string;
  is_late?: boolean;
}

const STATUS_VARIANT: Record<string, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  present:  'success',
  late:     'warning',
  halfday:  'warning',
  absent:   'danger',
  on_leave: 'info',
  holiday:  'neutral',
};

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

function fmtTime(value: string | null): string {
  if (!value) return '—';
  const s = String(value);
  if (s.includes('T')) return s.slice(11, 16);
  return s.slice(0, 5);
}

function toLocalYYYYMM(year: number, month: number): { from: string; to: string } {
  const from = `${year}-${String(month).padStart(2, '0')}-01`;
  const lastDay = new Date(year, month, 0).getDate();
  const to = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
  return { from, to };
}

export default function SelfServiceDtrPage() {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1); // 1-12

  const { from, to } = useMemo(() => toLocalYYYYMM(year, month), [year, month]);

  const isCurrentMonth = year === now.getFullYear() && month === now.getMonth() + 1;
  const isEarliestMonth = year === now.getFullYear() - 1 && month === 1; // cap 13 months back

  const goBack = () => {
    if (month === 1) { setYear((y) => y - 1); setMonth(12); }
    else { setMonth((m) => m - 1); }
  };
  const goForward = () => {
    if (!isCurrentMonth) {
      if (month === 12) { setYear((y) => y + 1); setMonth(1); }
      else { setMonth((m) => m + 1); }
    }
  };

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'dtr', from, to],
    queryFn: () =>
      client
        .get<{ data: AttendanceRow[]; meta: unknown }>('/attendance/attendances', {
          params: { per_page: 100, scope: 'self', from, to },
        })
        .then((r) => r.data),
  });

  const rows: AttendanceRow[] = data?.data ?? [];

  return (
    <div>
      <PageHeader title="Daily Time Record" backTo="/self-service" backLabel="Dashboard" />

      {/* Month picker */}
      <div className="flex items-center justify-between px-5 py-3 border-b border-default">
        <button
          type="button"
          onClick={goBack}
          disabled={isEarliestMonth}
          className="w-9 h-9 rounded-md flex items-center justify-center hover:bg-elevated disabled:opacity-40"
          aria-label="Previous month"
        >
          <ChevronLeft size={18} />
        </button>
        <span className="text-sm font-medium">
          {MONTH_NAMES[month - 1]} {year}
        </span>
        <button
          type="button"
          onClick={goForward}
          disabled={isCurrentMonth}
          className="w-9 h-9 rounded-md flex items-center justify-center hover:bg-elevated disabled:opacity-40"
          aria-label="Next month"
        >
          <ChevronRight size={18} />
        </button>
      </div>

      <div className="px-5 py-4">
        {isLoading && (
          <div className="space-y-2">
            {[1, 2, 3, 4, 5].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}
          </div>
        )}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Couldn't load attendance"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}
        {!isLoading && !isError && rows.length === 0 && (
          <EmptyState
            icon="calendar"
            title={`No records for ${MONTH_NAMES[month - 1]} ${year}`}
          />
        )}
        {rows.length > 0 && (
          <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
            {rows.map((row) => (
              <li
                key={row.id}
                className={cn(
                  'px-3 py-2.5',
                  row.status === 'absent' && 'bg-danger/5',
                )}
              >
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <div className="text-sm font-mono tabular-nums font-medium">
                      {row.date}
                    </div>
                    <div className="text-xs text-muted">
                      {fmtTime(row.time_in)} → {fmtTime(row.time_out)}
                      {row.regular_hours != null && (
                        <span className="ml-2 font-mono tabular-nums">
                          {Number(row.regular_hours).toFixed(1)}h
                        </span>
                      )}
                    </div>
                  </div>
                  {row.status && (
                    <Chip variant={STATUS_VARIANT[row.status] ?? 'neutral'}>
                      {row.status.replace(/_/g, ' ')}
                    </Chip>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Verify types**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | grep "self-service/dtr"
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add spa/src/pages/self-service/dtr.tsx
git commit -m "feat: self-service DTR — month picker for previous months"
```

---

## Task 4: Loan Amortization Preview

In `loans.tsx`, the apply form calculates amortization on the frontend as `amount ÷ periods`. The backend has `POST /loans/preview-amortization` that returns a full schedule. Wire this up so the employee sees an estimated monthly deduction table before submitting. The backend endpoint takes `principal` (decimal) and `pay_periods` (integer) — note the param names differ from the apply form.

**Files:**
- Modify: `spa/src/pages/self-service/loans.tsx`
- Modify: `spa/src/api/self-service.ts` (add `previewLoanAmortization`)
- Modify: `spa/src/types/self-service.ts` (add `LoanAmortizationPreview`)

---

- [ ] **Step 1: Add type and API method**

In `spa/src/types/self-service.ts`, append:

```typescript
// ─── Loan amortization preview (Task SS-LP) ───────────────────────
export interface LoanAmortizationRow {
  period: number;
  amount: string;
  running_balance: string;
}

export interface LoanAmortizationPreview {
  monthly_amortization: string;
  schedule: LoanAmortizationRow[];
}
```

In `spa/src/api/self-service.ts`, add inside `selfServiceApi`:

```typescript
  previewLoanAmortization: (principal: number, periods: number) =>
    client
      .post<{ data: LoanAmortizationPreview }>('/loans/preview-amortization', {
        principal: principal.toFixed(2),
        pay_periods: periods,
      })
      .then((r) => r.data.data),
```

Also add to the import at top of `self-service.ts`:
```typescript
  LoanAmortizationPreview,
  LoanAmortizationRow,
```

- [ ] **Step 2: Add preview state to ApplyLoanSheet**

In `loans.tsx`, in the `ApplyLoanSheet` component, add preview fetching. The full component becomes:

```tsx
function ApplyLoanSheet({
  isOpen,
  onClose,
  onSubmit,
  pending,
}: {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (v: FormValues) => void;
  pending: boolean;
}) {
  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
    reset,
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { loan_type: 'company_loan', amount: 0, periods: 6, reason: '' },
  });

  const watchedAmount = watch('amount');
  const watchedPeriods = watch('periods');

  const { data: preview, isFetching: previewLoading } = useQuery({
    queryKey: ['loan-preview', watchedAmount, watchedPeriods],
    queryFn: () => selfServiceApi.previewLoanAmortization(
      Number(watchedAmount),
      Number(watchedPeriods),
    ),
    enabled: Number(watchedAmount) > 0 && Number(watchedPeriods) >= 1,
    staleTime: 30_000,
  });

  return (
    <BottomSheet
      isOpen={isOpen}
      onClose={() => {
        reset();
        onClose();
      }}
      title="Apply for a Loan"
    >
      <form
        onSubmit={handleSubmit((v) => onSubmit(v))}
        className="space-y-4"
      >
        <Select
          label="Type"
          {...register('loan_type')}
          error={errors.loan_type?.message}
          required
        >
          <option value="company_loan">Company Loan</option>
          <option value="cash_advance">Cash Advance</option>
        </Select>
        <Input
          label="Amount"
          type="number"
          step="0.01"
          {...register('amount')}
          error={errors.amount?.message}
          prefix="₱"
          className="font-mono"
          required
        />
        <Input
          label="Periods (months)"
          type="number"
          {...register('periods')}
          error={errors.periods?.message}
          required
        />

        {/* Amortization preview */}
        {Number(watchedAmount) > 0 && Number(watchedPeriods) >= 1 && (
          <div className="rounded-md border border-default bg-surface p-3 space-y-2">
            <div className="flex items-center justify-between text-xs text-muted">
              <span>Estimated monthly deduction</span>
              {previewLoading && <span className="font-mono tabular-nums">…</span>}
              {!previewLoading && preview && (
                <span className="font-mono tabular-nums font-medium text-primary">
                  ₱{preview.monthly_amortization}
                </span>
              )}
            </div>
            {preview && preview.schedule.length > 0 && (
              <div className="max-h-36 overflow-y-auto">
                <table className="w-full text-xs font-mono tabular-nums">
                  <thead>
                    <tr className="text-muted border-b border-subtle">
                      <th className="text-left py-1 font-normal">Period</th>
                      <th className="text-right py-1 font-normal">Deduction</th>
                      <th className="text-right py-1 font-normal">Balance</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-subtle">
                    {preview.schedule.slice(0, 12).map((row) => (
                      <tr key={row.period}>
                        <td className="py-1">{row.period}</td>
                        <td className="text-right py-1">₱{row.amount}</td>
                        <td className="text-right py-1 text-muted">₱{row.running_balance}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <p className="text-2xs text-muted">
              Estimate only — final schedule set after approval.
            </p>
          </div>
        )}

        <Textarea
          label="Reason (optional)"
          rows={3}
          {...register('reason')}
          error={errors.reason?.message}
        />
        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={pending} loading={pending}>
            {pending ? 'Submitting…' : 'Submit Request'}
          </Button>
        </div>
      </form>
    </BottomSheet>
  );
}
```

Add to imports at top of `loans.tsx`:
```typescript
import { useQuery } from '@tanstack/react-query'; // already imported, just confirm
```

- [ ] **Step 3: Verify types**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | grep "self-service/loans"
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add spa/src/pages/self-service/loans.tsx spa/src/api/self-service.ts spa/src/types/self-service.ts
git commit -m "feat: self-service loans — amortization schedule preview before submit"
```

---

## Task 5: Cancel Pending OT Request

Employees can submit OT requests but have no way to cancel them while pending. Add a "Cancel" button on pending rows in `overtime.tsx`. Backend needs a cancel route. The `OvertimeService::cancel()` or `PATCH /attendance/overtime-requests/{id}/cancel` needs adding to the attendance routes, or we add it to `SelfServiceController`.

**Files:**
- Modify: `api/app/Modules/HR/Controllers/SelfServiceController.php` (add `cancelOvertime`)
- Modify: `api/app/Modules/HR/routes.php` (add DELETE/PATCH cancel route)
- Modify: `spa/src/pages/self-service/overtime.tsx` (add cancel button)
- Modify: `spa/src/api/self-service.ts` (add `cancelOvertime`)
- Modify: `spa/src/types/self-service.ts` (no changes needed)

---

- [ ] **Step 1: Add cancelOvertime to SelfServiceController**

In `api/app/Modules/HR/Controllers/SelfServiceController.php`, add this method after `applyOvertime()`:

```php
/**
 * Cancel a pending overtime request. Only the owning employee can cancel,
 * and only while the request is still pending (not approved/rejected).
 */
public function cancelOvertime(Request $request, string $id): JsonResponse
{
    $employee = $this->currentEmployee($request);

    $decoded = app('hashids')->decode($id);
    abort_if(empty($decoded), 404);

    /** @var OvertimeRequest|null $ot */
    $ot = OvertimeRequest::query()
        ->where('id', $decoded[0])
        ->where('employee_id', $employee->id)
        ->first();

    abort_if(! $ot, 404);
    abort_if($ot->status !== OvertimeStatus::Pending, 422, 'Only pending requests can be cancelled.');

    $ot->update(['status' => OvertimeStatus::Rejected, 'rejection_reason' => 'Cancelled by employee.']);

    return response()->json(['message' => 'Overtime request cancelled.']);
}
```

- [ ] **Step 2: Add route in HR routes.php**

In `api/app/Modules/HR/routes.php`, inside the `self-service` prefix group, add after the existing overtime routes:

```php
Route::delete('/overtime/{id}',           [SelfServiceController::class, 'cancelOvertime']);
```

The full self-service overtime block should look like:

```php
Route::get('/overtime',                   [SelfServiceController::class, 'overtime']);
Route::post('/overtime',                  [SelfServiceController::class, 'applyOvertime']);
Route::delete('/overtime/{id}',           [SelfServiceController::class, 'cancelOvertime']);
```

- [ ] **Step 3: Verify route registered**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan route:list | grep "self-service/overtime"
```

Expected: Lines showing `GET`, `POST`, and `DELETE` for `api/v1/hr/self-service/overtime`.

- [ ] **Step 4: Add API method**

In `spa/src/api/self-service.ts`, add inside `selfServiceApi`:

```typescript
  cancelOvertime: (id: string) =>
    client
      .delete<{ message: string }>(`/hr/self-service/overtime/${id}`)
      .then((r) => r.data),
```

- [ ] **Step 5: Add Cancel button to overtime pending list**

In `spa/src/pages/self-service/overtime.tsx`, update `RequestList` to accept an `onCancel` prop for pending rows:

```tsx
function RequestList({
  rows,
  onCancel,
}: {
  rows: SelfServiceOvertimeRequest[];
  onCancel?: (id: string) => void;
}) {
  return (
    <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
      {rows.map((r) => (
        <li key={r.id} className="px-3 py-2.5">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0 flex-1">
              <div className="text-sm font-medium font-mono tabular-nums">
                {r.date ?? '—'} · {r.hours_requested}h OT
              </div>
              {r.reason && <div className="text-xs text-muted truncate">{r.reason}</div>}
              {r.status === 'rejected' && r.rejection_reason && (
                <div className="text-xs text-danger mt-0.5">Reason: {r.rejection_reason}</div>
              )}
            </div>
            <div className="flex items-center gap-2 shrink-0">
              <Chip variant={r.status ? STATUS_CHIP[r.status] : 'neutral'}>
                {r.status === 'pending' ? 'Pending approval' : r.status ?? '—'}
              </Chip>
              {onCancel && r.status === 'pending' && (
                <button
                  type="button"
                  onClick={() => onCancel(r.id)}
                  className="text-2xs text-danger hover:underline"
                  aria-label="Cancel this overtime request"
                >
                  Cancel
                </button>
              )}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 6: Wire onCancel mutation into page**

In `SelfServiceOvertimePage`, add cancel mutation:

```tsx
  const cancel = useMutation({
    mutationFn: (id: string) => selfServiceApi.cancelOvertime(id),
    onSuccess: () => {
      toast.success('Overtime request cancelled.');
      queryClient.invalidateQueries({ queryKey: ['self-service', 'overtime'] });
    },
    onError: () => toast.error('Failed to cancel request.'),
  });
```

And update the `<RequestList>` call for the Pending section:

```tsx
<RequestList rows={data.pending} onCancel={(id) => cancel.mutate(id)} />
```

- [ ] **Step 7: Verify types**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | grep "self-service/overtime"
```

Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add api/app/Modules/HR/Controllers/SelfServiceController.php api/app/Modules/HR/routes.php spa/src/pages/self-service/overtime.tsx spa/src/api/self-service.ts
git commit -m "feat: self-service OT — cancel pending overtime request"
```

---

## Self-Review

**Spec coverage:**
1. ✅ Task 1 — in-portal leave filing (no more redirect to HR module)
2. ✅ Task 2 — leave balance progress bars on home dashboard
3. ✅ Task 3 — DTR month picker
4. ✅ Task 4 — loan amortization preview table
5. ✅ Task 5 — cancel pending OT requests

**Placeholder scan:** None found. All code blocks are complete.

**Type consistency:**
- `SelfServiceLeaveType` defined in Task 1, used in Task 1 ✅
- `LoanAmortizationPreview` / `LoanAmortizationRow` defined in Task 4, used in Task 4 ✅
- `cancelOvertime(id: string)` in api, called with `r.id` (string from `SelfServiceOvertimeRequest`) ✅
- DTR uses `/attendance/attendances` — verify actual API path in next step. The `AttendanceService` is under `Attendance` module and routes are prefixed `/attendance/attendances` based on routes.php.

**One cross-check needed:** The current `dtr.tsx` calls `/attendances` (no prefix). The routes file shows `Route::prefix('attendance')` then `Route::get('/attendances', ...)` = `/api/v1/attendance/attendances`. But the current working code uses `/attendances` — check which is correct before running. If current code works with `/attendances`, keep it; if it's actually `/attendance/attendances`, the existing code was already wrong but apparently working (maybe the route is also registered at the root). Keep the same path as the working original file — just add `from` and `to` params.

**Fix for Task 3:** In the DTR rewrite, use the same API path as the working original (`/attendances`, not `/attendance/attendances`). The `queryFn` should be:

```typescript
    queryFn: () =>
      client
        .get<{ data: AttendanceRow[]; meta: unknown }>('/attendances', {
          params: { per_page: 100, scope: 'self', from, to },
        })
        .then((r) => r.data),
```
