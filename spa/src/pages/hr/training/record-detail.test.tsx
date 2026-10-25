import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { employeeTrainingsApi } from '@/api/hr/employee-trainings';
import { selfServiceApi } from '@/api/self-service';
import type { EmployeeTraining } from '@/types/hr';
import TrainingRecordDetailPage from './record-detail';

vi.mock('@/api/hr/employee-trainings', () => ({
  employeeTrainingsApi: { show: vi.fn() },
}));
vi.mock('@/api/self-service', () => ({
  selfServiceApi: { training: vi.fn() },
}));

const record: EmployeeTraining = {
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
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(employeeTrainingsApi.show).mockResolvedValue(record);
  vi.mocked(selfServiceApi.training).mockResolvedValue(record);
});

function renderAt(path: string, selfService: boolean) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route
            path={selfService ? '/self-service/trainings/:id' : '/hr/employee-trainings/:id'}
            element={<TrainingRecordDetailPage selfService={selfService} />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

it('loads the owner-only self-service training record', async () => {
  renderAt('/self-service/trainings/training-record-hash', true);

  expect(await screen.findAllByText('Completed')).toHaveLength(2);
  expect(selfServiceApi.training).toHaveBeenCalledWith('training-record-hash');
  expect(employeeTrainingsApi.show).not.toHaveBeenCalled();
});

it('loads the scoped HR training record with employee context', async () => {
  renderAt('/hr/employee-trainings/training-record-hash', false);

  expect(await screen.findAllByText('Mika Tanaka')).toHaveLength(2);
  expect(employeeTrainingsApi.show).toHaveBeenCalledWith('training-record-hash');
  expect(selfServiceApi.training).not.toHaveBeenCalled();
});
