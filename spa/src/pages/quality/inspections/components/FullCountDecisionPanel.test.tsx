import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection } from '@/types/quality';
import { FullCountDecisionPanel } from './FullCountDecisionPanel';

vi.mock('@/api/quality/inspections', () => ({ inspectionsApi: { recordBatchResult: vi.fn() } }));

const inspection = {
  id: 'count-qc', status: 'draft', result_version: 0, batch_quantity: 100,
  source_uom_code: 'PCS', measurements: [{ id: 'count-row', item_result: null }],
} as unknown as Inspection;

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({ ...inspection, status: 'awaiting_review' });
});

function panel() {
  return render(<QueryClientProvider client={new QueryClient()}>
    <MemoryRouter><FullCountDecisionPanel inspection={inspection} canManage /></MemoryRouter>
  </QueryClientProvider>);
}

it('requires full-count confirmation and submits zero defects', async () => {
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), { target: { value: '0' } });
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: /I counted all 100 received pieces/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('count-qc', {
    expected_result_version: 0, coverage_confirmed: true, inspected_quantity: 100,
    sample_defect_count: 0, physical_defects_confirmed: false,
    items: [{ id: 'count-row', result: 'passed' }],
  }));
});

it('accepts good pieces only after reason and segregation, rejects overcount', async () => {
  panel();
  const defects = screen.getByRole('spinbutton', { name: 'Defective pieces' });
  fireEvent.change(defects, { target: { value: '101' } });
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.change(defects, { target: { value: '2' } });
  expect(screen.getByText('98 pieces eligible for acceptance')).toBeVisible();
  fireEvent.click(screen.getByRole('checkbox', { name: /I counted all 100 received pieces/ }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Defect reason' }), { target: { value: 'Two torn bags' } });
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: 'I segregated the defective pieces' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('count-qc', expect.objectContaining({
    sample_defect_count: 2, problem_description: 'Two torn bags', defects_removed_confirmed: true,
  })));
});

it('holds the whole receipt when every piece is defective and segregated', async () => {
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), { target: { value: '100' } });
  fireEvent.click(screen.getByRole('checkbox', { name: /I counted all 100 received pieces/ }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Defect reason' }), { target: { value: 'All bags torn' } });
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: 'I segregated the defective pieces' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('count-qc', expect.objectContaining({
    sample_defect_count: 100, defects_removed_confirmed: true,
  })));
});
