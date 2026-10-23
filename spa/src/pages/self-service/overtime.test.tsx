import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { selfServiceApi } from '@/api/self-service';
import type { SelfServiceOvertimeRequest } from '@/types/self-service';
import SelfServiceOvertimePage from './overtime';

vi.mock('@/api/self-service', () => ({ selfServiceApi: { overtime: vi.fn(), applyOvertime: vi.fn() } }));
beforeEach(() => {
  vi.clearAllMocks();
  vi.setSystemTime(new Date('2026-10-09T18:00:00Z'));
  vi.mocked(selfServiceApi.overtime).mockResolvedValue({
    pending: [], history: [], todays_shift: null, hourly_rate: '100.00',
    minimum_hours: 0.5, maximum_hours: 4, premium_multiplier: 1.25,
  });
  vi.mocked(selfServiceApi.applyOvertime).mockImplementation(() => new Promise(() => {}));
});
afterEach(() => vi.useRealTimers());

it.each(['2026-10-10', '2026-10-11'])('blocks past overtime dates and accepts %s in Manila time', async (allowedDate) => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><SelfServiceOvertimePage /></MemoryRouter>
    </QueryClientProvider>,
  );
  fireEvent.click((await screen.findAllByRole('button', { name: 'Apply for OT' }))[0]);
  const date = screen.getByLabelText(/^Date/);
  expect(date).toHaveAttribute('min', '2026-10-10');
  expect(date).toHaveValue('2026-10-10');
  fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Manual overtime test' } });
  fireEvent.change(date, { target: { value: '2026-10-09' } });
  fireEvent.click(screen.getByRole('button', { name: 'Submit for approval' }));
  expect(await screen.findByText('Choose today or a later date.')).toBeInTheDocument();
  expect(selfServiceApi.applyOvertime).not.toHaveBeenCalled();
  fireEvent.change(date, { target: { value: allowedDate } });
  fireEvent.click(screen.getByRole('button', { name: 'Submit for approval' }));
  await waitFor(() => expect(selfServiceApi.applyOvertime).toHaveBeenCalledWith({
    date: allowedDate, hours_requested: 0.5, reason: 'Manual overtime test',
  }));
});

it('opens only the overtime request named by its deep link', async () => {
  const request = (id: string, reason: string): SelfServiceOvertimeRequest => ({
    id, date: '2026-10-10', hours_requested: '2.0', reason, status: 'approved',
    status_label: 'Approved', rejection_reason: null, cancelled_at: null,
    can_restore: false, approver: 'Approver', created_at: '2026-10-09T00:00:00Z',
  });
  vi.mocked(selfServiceApi.overtime).mockResolvedValue({
    pending: [], history: [request('ot-selected', 'selected request'), request('ot-other', 'other request')],
    todays_shift: null, hourly_rate: null, minimum_hours: 0.5, maximum_hours: 4, premium_multiplier: 1.25,
  });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/self-service/overtime/ot-selected']}>
        <Routes><Route path="/self-service/overtime/:id" element={<SelfServiceOvertimePage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('selected request')).toBeInTheDocument();
  expect(screen.queryByText('other request')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Apply for OT' })).not.toBeInTheDocument();
  expect(selfServiceApi.overtime).toHaveBeenCalledWith('ot-selected');
});
