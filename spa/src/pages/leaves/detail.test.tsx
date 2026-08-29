import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import LeaveDetailPage from './detail';
import { leaveRequestsApi, leaveBalancesApi } from '@/api/leave';
import type { EmployeeLeaveBalance, LeaveRequest } from '@/types/leave';

/**
 * M019-F27 — archiving a leave type makes the API OMIT `leave_type` from a
 * balance row (EmployeeLeaveBalanceResource uses whenLoaded(), which drops the
 * key when the eager-loaded relation resolves to null). Six SPA sites used to
 * dereference it against a non-nullable type, so a single archived type threw a
 * TypeError and took the whole panel down with an ErrorBoundary.
 */
vi.mock('@/api/leave', () => ({
  leaveRequestsApi: { show: vi.fn(), options: vi.fn() },
  leaveBalancesApi: { forEmployee: vi.fn() },
}));

vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'lr-1' }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: () => ({ id: 'u-1', employee_id: 'e-1' }),
}));

vi.mock('@/components/guards/CanDo', () => ({
  CanDo: () => null,
}));

const request = {
  id: 'lr-1',
  leave_request_no: 'LR-202608-0001',
  employee: { id: 'e-1', employee_no: 'OGM-2026-0001', full_name: 'Ana Dela Cruz', department: 'Production' },
  leave_type: { id: 'lt-vl', code: 'VL', name: 'Vacation Leave' },
  start_date: '2026-09-07',
  end_date: '2026-09-07',
  days: '1.0',
  half_day_period: null,
  reason: 'Family matter',
  has_document: false,
  status: 'pending_hr',
  status_label: 'Pending HR approval',
  dept_approver: null,
  dept_approved_at: null,
  hr_approver: null,
  hr_approved_at: null,
  cancelled_by: null,
  cancelled_at: null,
  rejection_reason: null,
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
  deleted_at: null,
} as unknown as LeaveRequest;

/** The archived case: the key is absent, not null. */
const archivedBalance = {
  id: 'bal-archived',
  employee_id: 'e-1',
  year: 2026,
  total_credits: '5.0',
  used: '0.0',
  remaining: '5.0',
} as unknown as EmployeeLeaveBalance;

const liveBalance: EmployeeLeaveBalance = {
  id: 'bal-vl',
  employee_id: 'e-1',
  leave_type: { id: 'lt-vl', code: 'VL', name: 'Vacation Leave' },
  year: 2026,
  total_credits: '15.0',
  used: '2.0',
  remaining: '13.0',
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={['/hr/leaves/lr-1']}>
      <QueryClientProvider client={client}>
        <LeaveDetailPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

describe('LeaveDetailPage balance panel', () => {
  it('renders a balance row whose leave type has been archived without crashing', async () => {
    vi.mocked(leaveRequestsApi.show).mockResolvedValue(request);
    vi.mocked(leaveRequestsApi.options).mockResolvedValue({
      statuses: [],
      half_day_periods: [],
    } as never);
    vi.mocked(leaveBalancesApi.forEmployee).mockResolvedValue([archivedBalance, liveBalance]);

    renderPage();

    // The archived row falls back to a label instead of throwing on
    // `b.leave_type.code`, and the live row is unaffected.
    expect(await screen.findByText('Archived type')).toBeInTheDocument();
    expect(await screen.findByText('VL')).toBeInTheDocument();
  });
});
