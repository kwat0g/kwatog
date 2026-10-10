import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AssignmentPanel } from './AssignmentPanel';
import { workOrdersApi, type MaintenanceAssigneeCandidate } from '@/api/maintenance/workOrders';
import type { MaintenanceWorkOrder } from '@/types/maintenance';

vi.mock('@/api/maintenance/workOrders', () => ({
  workOrdersApi: { assigneeSuggestions: vi.fn(), assign: vi.fn() },
}));

afterEach(() => { cleanup(); vi.resetAllMocks(); });

const candidate = (id: string, name: string, available: boolean): MaintenanceAssigneeCandidate => ({
  id, name, employee_no: `EMP-${id}`, active_load: 0,
  can_assign: available, can_attest_presence: false,
  blocked_reason: available ? null : 'Outside HR shift hours or working days.',
  presence: { state: available ? 'present' : 'blocked', reason: null, reason_code: null },
});

const order = {
  id: 'mwo-4', status: 'assigned', verification_required: false,
  assignee: { id: 'josefa', name: 'Josefa Castillo', employee_no: 'EMP-josefa' },
} as MaintenanceWorkOrder;

function show(workOrder = order) {
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <AssignmentPanel order={workOrder} />
  </QueryClientProvider>);
}

it('shows the suggestion first, explains unavailable staff, and prevents unchanged assignments', async () => {
  vi.mocked(workOrdersApi.assigneeSuggestions).mockResolvedValue({
    suggested_employee_id: 'josefa', evaluated_at: '2026-10-10T03:59:00+08:00',
    candidates: [candidate('josefa', 'Josefa Castillo', true), candidate('rafael', 'Rafael Castillo', true), candidate('juan', 'Juan Bautista', false)],
  });
  vi.mocked(workOrdersApi.assign).mockResolvedValue(order);
  show();

  expect(await screen.findByRole('radio', { name: /Josefa Castillo/ })).toBeChecked();
  expect(screen.getByText('Suggested')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Change assignee' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Review unavailable technicians (1)' }));
  expect(screen.getByText('Outside HR shift hours or working days.')).toBeVisible();
  fireEvent.click(screen.getByRole('radio', { name: /Rafael Castillo/ }));
  expect(screen.getByRole('button', { name: 'Change assignee' })).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Change assignee' }));
  await waitFor(() => expect(workOrdersApi.assign).toHaveBeenCalledWith('mwo-4', 'rafael', {
    expected_assignee_id: 'josefa', presence_attestation_reason: undefined, handover_note: undefined,
  }));
});

it('requires presence attestation and disables blocked technicians on verified orders', async () => {
  vi.mocked(workOrdersApi.assigneeSuggestions).mockResolvedValue({
    suggested_employee_id: null, evaluated_at: '2026-10-10T03:59:00+08:00',
    candidates: [
      { ...candidate('josefa', 'Josefa Castillo', false), can_attest_presence: true, blocked_reason: 'Attendance unverified.' },
      candidate('juan', 'Juan Bautista', false),
    ],
  });
  show({ ...order, assignee: null, status: 'open', verification_required: true });

  fireEvent.click(await screen.findByRole('radio', { name: /Josefa Castillo/ }));
  expect(screen.getByRole('button', { name: 'Assign technician' })).toBeDisabled();
  fireEvent.change(screen.getByRole('textbox', { name: 'Presence attestation reason' }), { target: { value: 'Present in workshop' } });
  expect(screen.getByRole('button', { name: 'Assign technician' })).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Review unavailable technicians (1)' }));
  expect(screen.getByRole('radio', { name: /Juan Bautista/ })).toBeDisabled();
});

it('refreshes availability before assignment and shows the new blocking reason', async () => {
  vi.mocked(workOrdersApi.assigneeSuggestions)
    .mockResolvedValueOnce({
      suggested_employee_id: 'josefa', evaluated_at: '2026-10-10T03:59:00+08:00',
      candidates: [candidate('josefa', 'Josefa Castillo', true), candidate('rafael', 'Rafael Castillo', true)],
    })
    .mockResolvedValueOnce({
      suggested_employee_id: 'josefa', evaluated_at: '2026-10-10T04:00:00+08:00',
      candidates: [candidate('josefa', 'Josefa Castillo', true), {
        ...candidate('rafael', 'Rafael Castillo', false),
        blocked_reason: 'Technician is already working on another maintenance order.',
      }],
    });
  vi.mocked(workOrdersApi.assign).mockResolvedValue(order);
  show();

  fireEvent.click(await screen.findByRole('radio', { name: /Rafael Castillo/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Change assignee' }));

  expect(await screen.findByRole('alert')).toHaveTextContent(/Technician is already working on another maintenance order\. Choose another technician or update attendance\/shift coverage, then refresh\./);
  expect(workOrdersApi.assigneeSuggestions).toHaveBeenCalledTimes(2);
  expect(workOrdersApi.assign).not.toHaveBeenCalled();
});
