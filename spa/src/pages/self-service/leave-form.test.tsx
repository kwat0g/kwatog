import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { leaveBalancesApi, leaveRequestsApi, leaveTypesApi } from '@/api/leave';
import { selfServiceApi } from '@/api/self-service';
import CreateLeavePage from '@/pages/leaves/create';
import SelfServiceLeavePage from './leave';

vi.mock('@/api/leave', () => ({
  leaveRequestsApi: { options: vi.fn(), create: vi.fn() },
  leaveTypesApi: { list: vi.fn() },
  leaveBalancesApi: { me: vi.fn() },
}));
vi.mock('@/api/self-service', () => ({
  selfServiceApi: {
    leaveRequests: vi.fn(), leaveTypes: vi.fn(), leaveBalancesMe: vi.fn(), fileLeaveSelf: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: () => ({ id: 'user-1', employee: { id: 'employee-1' }, role: { slug: 'department_head' } }),
}));

beforeEach(() => {
  vi.setSystemTime(new Date('2026-10-09T18:00:00Z'));
  vi.clearAllMocks();
  localStorage.clear();
  vi.mocked(leaveRequestsApi.options).mockResolvedValue({
    statuses: [], holiday_dates: [],
    half_day_periods: [
      { value: 'am', label: 'Morning (AM half-day)' },
      { value: 'pm', label: 'Afternoon (PM half-day)' },
    ],
  });
  const types = [{ id: 'vl', name: 'Vacation Leave', code: 'VL', requires_document: false }];
  vi.mocked(leaveTypesApi.list).mockResolvedValue({ data: types } as never);
  vi.mocked(selfServiceApi.leaveTypes).mockResolvedValue(types as never);
  vi.mocked(selfServiceApi.leaveRequests).mockResolvedValue({ data: [] } as never);
  vi.mocked(leaveBalancesApi.me).mockResolvedValue([]);
  vi.mocked(selfServiceApi.leaveBalancesMe).mockResolvedValue([]);
  vi.mocked(leaveRequestsApi.create).mockImplementation(() => new Promise(() => {}));
  vi.mocked(selfServiceApi.fileLeaveSelf).mockImplementation(() => new Promise(() => {}));
});
afterEach(() => vi.useRealTimers());

describe.each(['employee', 'HR'] as const)('%s leave form', (side) => {
  it('blocks today and past dates and end dates before the selected start in Manila time', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>{side === 'employee' ? <SelfServiceLeavePage /> : <CreateLeavePage />}</MemoryRouter>
      </QueryClientProvider>,
    );
    if (side === 'employee') fireEvent.click(await screen.findByRole('button', { name: 'New request' }));
    await screen.findByRole('option', { name: 'Morning (AM half-day)' });
    const start = screen.getByLabelText(/Start date/);
    const end = screen.getByLabelText(/End date/);
    expect(start).toHaveAttribute('min', '2026-10-11');
    expect(end).toHaveAttribute('min', '2026-10-11');
    fireEvent.change(start, { target: { value: '2026-10-09' } });
    expect(start).toBeInvalid();
    fireEvent.change(start, { target: { value: '2026-10-10' } });
    expect(start).toBeInvalid();
    fireEvent.change(start, { target: { value: '2026-10-13' } });
    expect(end).toHaveAttribute('min', '2026-10-13');
    fireEvent.change(end, { target: { value: '2026-10-12' } });
    expect(end).toBeInvalid();
    const duration = screen.getByLabelText(side === 'employee' ? 'Half day' : 'Half-day');
    fireEvent.change(duration, { target: { value: 'am' } });
    expect(start).toHaveAttribute('min', '2026-10-10');
    fireEvent.change(start, { target: { value: '2026-10-10' } });
    fireEvent.change(end, { target: { value: '2026-10-10' } });
    expect(start).toBeValid();
    expect(end).toBeValid();
    fireEvent.change(start, { target: { value: '2026-10-09' } });
    expect(start).toBeInvalid();
    fireEvent.change(start, { target: { value: '2026-10-10' } });
    fireEvent.change(duration, { target: { value: 'pm' } });
    expect(start).toBeValid();
    fireEvent.change(duration, { target: { value: 'none' } });
    expect(start).toBeInvalid();
  });

  it.each([
    ['none', '2026-10-13'], ['am', '2026-10-10'], ['pm', '2026-10-10'],
    ['am', '2026-10-13'], ['pm', '2026-10-13'],
  ])('submits duration %s on %s after options load', async (period, date) => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          {side === 'employee' ? <SelfServiceLeavePage /> : <CreateLeavePage />}
        </MemoryRouter>
      </QueryClientProvider>,
    );
    if (side === 'employee') fireEvent.click(await screen.findByRole('button', { name: 'New request' }));
    await screen.findByRole('option', { name: 'Morning (AM half-day)' });
    const duration = screen.getByLabelText(side === 'employee' ? 'Half day' : 'Half-day');
    expect(duration).toHaveValue('none');
    expect(screen.getByRole('option', { name: 'Full day' })).toBeInTheDocument();
    if (side === 'employee') {
      fireEvent.change(screen.getByLabelText(/Leave type/), { target: { value: 'vl' } });
    } else {
      fireEvent.click(screen.getByRole('button', { name: 'Show Leave type' }));
      fireEvent.click(screen.getByRole('option', { name: 'VL — Vacation Leave' }));
    }
    fireEvent.change(screen.getByLabelText(/Start date/), { target: { value: date } });
    fireEvent.change(screen.getByLabelText(/End date/), { target: { value: date } });
    if (period !== 'none') fireEvent.change(duration, { target: { value: period } });
    const submit = screen.getByRole('button', { name: 'Submit request' });
    await waitFor(() => expect(submit).toBeEnabled());
    fireEvent.click(submit);
    await waitFor(() => expect(side === 'employee' ? selfServiceApi.fileLeaveSelf : leaveRequestsApi.create)
      .toHaveBeenCalledWith(expect.objectContaining({
        employee_id: 'employee-1', leave_type_id: 'vl',
        start_date: date, end_date: date,
        half_day_period: period === 'none' ? undefined : period,
      })));
  });
});
