import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CalibrationFormPage from './form';
import { calibrationApi } from '@/api/quality/calibration';

vi.mock('@/api/quality/calibration', () => ({
 calibrationApi: {
  show: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
 },
}));

const today = new Date().toISOString().slice(0, 10);
function offsetDate(days: number): string {
 const d = new Date();
 d.setDate(d.getDate() + days);
 return d.toISOString().slice(0, 10);
}

function renderForm(path: string) {
 const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

 return render(
  <QueryClientProvider client={queryClient}>
   <MemoryRouter initialEntries={[path]}>
    <Routes>
     <Route path="/quality/calibration/new" element={<CalibrationFormPage />} />
     <Route path="/quality/calibration/:id/edit" element={<CalibrationFormPage />} />
    </Routes>
   </MemoryRouter>
  </QueryClientProvider>,
 );
}

describe('CalibrationFormPage', () => {
 beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.clear();
 });

 it('offers a retry affordance when the instrument cannot be loaded', async () => {
  vi.mocked(calibrationApi.show).mockRejectedValue(new Error('boom'));

  renderForm('/quality/calibration/abc123/edit');

  expect(await screen.findByText(/could not load this calibration instrument/i)).toBeInTheDocument();
  const retry = screen.getByRole('button', { name: /try again/i });
  fireEvent.click(retry);
  await waitFor(() => expect(calibrationApi.show).toHaveBeenCalledTimes(2));
 });

 /**
  * `max` on the picker is the first of three layers. It makes the value
  * out-of-range, so the form never submits and the future date never reaches
  * the API. The Zod refine behind it covers a value that arrives without a
  * change event (a restored draft), and CalibrationService is authoritative.
  */
 it('refuses a future last-calibration date instead of sending it', async () => {
  renderForm('/quality/calibration/new');

  fireEvent.change(screen.getByLabelText(/equipment code/i), { target: { value: 'CAL-UI-1' } });
  fireEvent.change(screen.getByLabelText(/^name/i), { target: { value: 'Caliper' } });
  const lastCalibrated = screen.getByLabelText(/last calibrated/i) as HTMLInputElement;
  fireEvent.change(lastCalibrated, { target: { value: offsetDate(5) } });

  expect(lastCalibrated.validity.rangeOverflow).toBe(true);

  fireEvent.click(screen.getByRole('button', { name: /register instrument/i }));

  await waitFor(() => expect(calibrationApi.create).not.toHaveBeenCalled());
 });

 it('refuses a next-due date earlier than the last calibration date', async () => {
  renderForm('/quality/calibration/new');

  fireEvent.change(screen.getByLabelText(/equipment code/i), { target: { value: 'CAL-UI-2' } });
  fireEvent.change(screen.getByLabelText(/^name/i), { target: { value: 'Micrometer' } });
  fireEvent.change(screen.getByLabelText(/last calibrated/i), { target: { value: offsetDate(-10) } });
  fireEvent.change(screen.getByLabelText(/next due/i), { target: { value: offsetDate(-20) } });
  fireEvent.click(screen.getByRole('button', { name: /register instrument/i }));

  expect(
   await screen.findByText(/next calibration date cannot be earlier than the last calibration date/i),
  ).toBeInTheDocument();
  expect(calibrationApi.create).not.toHaveBeenCalled();
 });

 it('caps the last-calibrated picker at today', () => {
  renderForm('/quality/calibration/new');

  expect(screen.getByLabelText(/last calibrated/i)).toHaveAttribute('max', today);
 });
});
