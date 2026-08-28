import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttendancePage from './index';
import { attendancesApi } from '@/api/attendance/attendances';
import { departmentsApi } from '@/api/hr/departments';
import { employeesApi } from '@/api/hr/employees';
import { shiftsApi } from '@/api/attendance/shifts';
import type { PaginatedResponse } from '@/types';
import type { Attendance, Shift } from '@/types/attendance';

vi.mock('@/api/attendance/attendances', () => ({
 attendancesApi: {
  options: vi.fn(),
  list: vi.fn(),
  show: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
  restore: vi.fn(),
 },
}));

vi.mock('@/api/attendance/shifts', () => ({
 shiftsApi: {
  list: vi.fn(),
 },
}));

vi.mock('@/api/hr/departments', () => ({
 departmentsApi: {
  tree: vi.fn(),
 },
}));

vi.mock('@/api/hr/employees', () => ({
 employeesApi: {
  list: vi.fn(),
 },
}));

vi.mock('@/hooks/usePermission', () => ({
 usePermission: () => ({
  can: (permission: string) => permission === 'attendance.edit',
 }),
}));

const shift: Shift = {
 id: 'shift-1',
 name: 'Day Shift',
 start_time: '08:00:00',
 end_time: '17:00:00',
 break_minutes: 60,
 is_night_shift: false,
 is_extended: false,
 auto_ot_hours: null,
 is_active: true,
 is_default: false,
 created_at: '2026-08-01T00:00:00Z',
 updated_at: '2026-08-01T00:00:00Z',
};

const attendance: Attendance = {
 id: 'attendance-1',
 employee: { id: 'employee-1', full_name: 'Ada Lovelace', employee_no: 'OGM-000001' },
 date: '2026-08-28',
 shift,
 time_in: '2026-08-28T08:00:00+08:00',
 time_out: '2026-08-28T17:00:00+08:00',
 regular_hours: '8.00',
 overtime_hours: '0.00',
 night_diff_hours: '0.00',
 tardiness_minutes: 0,
 undertime_minutes: 0,
 holiday_type: null,
 is_rest_day: false,
 day_type_rate: '1.00',
 status: 'present',
 is_manual_entry: true,
 remarks: 'Original correction',
 deleted_at: null,
};

function page<T>(data: T[]): PaginatedResponse<T> {
 return {
  data,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length || null },
  links: { first: '', last: '', prev: null, next: null },
 };
}

function renderPage(): void {
 const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
 });

 render(
  <QueryClientProvider client={queryClient}>
   <MemoryRouter initialEntries={['/hr/attendance']}>
    <AttendancePage />
   </MemoryRouter>
  </QueryClientProvider>,
 );
}

describe('attendance correction clearing', () => {
 beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(attendancesApi.list).mockResolvedValue(page([attendance]));
  vi.mocked(attendancesApi.options).mockResolvedValue({ statuses: [] });
  vi.mocked(attendancesApi.update).mockResolvedValue(attendance);
  vi.mocked(employeesApi.list).mockResolvedValue(page([]));
  vi.mocked(shiftsApi.list).mockResolvedValue(page([shift]));
  vi.mocked(departmentsApi.tree).mockResolvedValue([]);
 });

 it('sends explicit nulls when an existing shift and both punches are cleared', async () => {
  renderPage();

  fireEvent.click(await screen.findByRole('row', { name: /Ada Lovelace/ }));
  const dialog = await screen.findByRole('dialog', { name: 'Correct attendance record' });

  fireEvent.change(within(dialog).getByLabelText('Shift'), { target: { value: '' } });
  fireEvent.change(within(dialog).getByLabelText('Time in'), { target: { value: '' } });
  fireEvent.change(within(dialog).getByLabelText('Time out'), { target: { value: '' } });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Save correction' }));

  await waitFor(() => expect(attendancesApi.update).toHaveBeenCalledWith(
   'attendance-1',
   expect.objectContaining({ shift_id: null, time_in: null, time_out: null }),
  ));

  const body = vi.mocked(attendancesApi.update).mock.calls[0]?.[1];
  expect(JSON.parse(JSON.stringify(body))).toEqual(expect.objectContaining({
   shift_id: null,
   time_in: null,
   time_out: null,
  }));
 });
});
