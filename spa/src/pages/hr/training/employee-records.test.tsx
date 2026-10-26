import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { employeeTrainingsApi } from '@/api/hr/employee-trainings';
import EmployeeTrainingRecordsPage from './employee-records';

vi.mock('@/api/hr/employee-trainings', () => ({
  employeeTrainingsApi: { listAll: vi.fn() },
}));

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(employeeTrainingsApi.listAll).mockResolvedValue({
    data: [{
      id: 'training-record-hash',
      employee: { id: 'employee-hash', full_name: 'Mika Tanaka' },
      training: { id: 'training-hash', name: 'Forklift Safety', validity_months: 12 },
      scheduled_for: '2026-10-01',
      completed_at: '2026-10-02',
      expires_at: '2027-10-02',
      status: 'completed',
      status_label: 'Completed',
      certificate: null,
      notes: null,
      last_alert_level: null,
      last_alert_at: null,
      created_at: '2026-10-01T00:00:00.000Z',
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
});

it('loads the filtered list and opens the selected training record', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/hr/employee-trainings?signal=expired']}>
        <Routes>
          <Route path="/hr/employee-trainings" element={<EmployeeTrainingRecordsPage />} />
          <Route path="/hr/employee-trainings/:id" element={<div data-testid="record-detail" />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('Mika Tanaka')).toBeVisible();
  expect(employeeTrainingsApi.listAll).toHaveBeenCalledWith(expect.objectContaining({ signal: 'expired' }));
  fireEvent.click(screen.getByText('Mika Tanaka'));
  expect(await screen.findByTestId('record-detail')).toBeVisible();
});
