import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BulkAssignShiftPage from './assign';
import { shiftsApi } from '@/api/attendance/shifts';

vi.mock('@/api/hr/departments', () => ({
  departmentsApi: {
    tree: vi.fn().mockResolvedValue([
      { id: 'department-1', name: 'Production' },
      { id: 'department-2', name: 'Quality' },
    ]),
  },
}));
vi.mock('@/api/attendance/shifts', () => ({
  shiftsApi: {
    list: vi.fn().mockResolvedValue({ data: [{ id: 'shift-1', name: 'Night shift' }] }),
    assignments: vi
      .fn()
      .mockResolvedValue({
        data: [
          {
            id: 'assignment-1',
            employee_name: 'Worker One',
            shift: { name: 'Night shift' },
            effective_date: '2026-10-01',
            end_date: null,
            working_days: null,
          },
        ],
        meta: { last_page: 1 },
      }),
    bulkAssign: vi.fn().mockResolvedValue({ count: 1 }),
    configureWorkingDays: vi.fn().mockResolvedValue({}),
  },
}));
beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);

it('requires explicit working days and configures a legacy calendar without assignment dates', async () => {
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <BulkAssignShiftPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  await screen.findByText('Production');
  fireEvent.change(screen.getByLabelText(/Department/), { target: { value: 'department-1' } });
  fireEvent.change(screen.getByLabelText(/^Shift/), { target: { value: 'shift-1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Assign shift' }));
  await screen.findByText('Select at least one working day');
  expect(shiftsApi.bulkAssign).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Assignment'), { target: { value: 'assignment-1' } });
  expect(screen.getByRole('option', { name: /Working days not configured/ })).toBeTruthy();
  fireEvent.click(screen.getAllByLabelText('Sunday')[1]);
  fireEvent.click(screen.getByRole('button', { name: 'Save working days' }));
  await waitFor(() =>
    expect(shiftsApi.configureWorkingDays).toHaveBeenCalledWith('assignment-1', [7]),
  );
});

it('retries a failed assignment list and clears selection and page when department changes', async () => {
  const result = {
    data: [
      {
        id: 'assignment-1',
        employee_name: 'Worker One',
        employee_id: 'worker-1',
        shift: { id: 'shift-1', name: 'Night shift' },
        effective_date: '2026-10-01',
        end_date: null,
        working_days: null,
      },
    ],
    meta: { last_page: 2 },
  };
  vi.mocked(shiftsApi.assignments).mockRejectedValue(new Error('Unavailable'));
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <BulkAssignShiftPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  await screen.findByRole('button', { name: 'Retry assignments' });
  vi.mocked(shiftsApi.assignments).mockResolvedValue(
    result as Awaited<ReturnType<typeof shiftsApi.assignments>>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Retry assignments' }));
  await screen.findByRole('option', { name: /Worker One/ });
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  await waitFor(() => expect(shiftsApi.assignments).toHaveBeenLastCalledWith('', 2));
  await screen.findByRole('option', { name: /Worker One/ });
  fireEvent.change(screen.getByLabelText('Assignment'), { target: { value: 'assignment-1' } });
  fireEvent.click(screen.getAllByLabelText('Sunday')[1]);
  fireEvent.change(screen.getByLabelText(/Department/), { target: { value: 'department-2' } });
  await waitFor(() => expect(shiftsApi.assignments).toHaveBeenLastCalledWith('department-2', 1));
  expect((screen.getByLabelText('Assignment') as HTMLSelectElement).value).toBe('');
  expect(screen.queryByRole('button', { name: 'Save working days' })).toBeNull();
  expect(shiftsApi.configureWorkingDays).not.toHaveBeenCalled();
});
