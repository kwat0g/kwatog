import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection } from '@/types/quality';
import { QuantityInspectionPanel } from './QuantityInspectionPanel';
vi.mock('@/api/quality/inspections', () => ({ inspectionsApi: { recordBatchResult: vi.fn() } }));
const source = { id: 'qc', stage: 'incoming', status: 'draft', result_version: 0, sampling_basis: 'lot', source_quantity: '500.000', source_uom_code: 'kg', batch_quantity: 1, counted_capture: { version: 1, required_quantity: 1, result_policy: 'zero_defects' }, measurements: [{ id: 'moisture', evaluation_mode: 'numeric' }] } as unknown as Inspection;
function panel(inspection = source) {
  render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><QuantityInspectionPanel inspection={inspection} canManage /></MemoryRouter></QueryClientProvider>);
}
beforeEach(() => { vi.clearAllMocks(); vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({ ...source, status: 'awaiting_review' }); });
it('records decimal defective kg with one explanation and no sample readings', async () => {
  panel();
  fireEvent.change(screen.getByLabelText(/Defective quantity/), { target: { value: '2.5' } });
  expect(screen.getAllByRole('textbox')).toHaveLength(1);
  expect(screen.queryByText(/Moisture/)).not.toBeInTheDocument();
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Contaminated resin' } });
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('qc', { expected_result_version: 0, quantity_result: { defective_quantity: '2.5', descriptions: ['Contaminated resin'] } }));
});
it('requires an explicit zero for PASS and rejects quantities above the receipt', () => {
  panel();
  const submit = screen.getByRole('button', { name: 'Submit inspection' });
  expect(submit).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Defective quantity/), { target: { value: '501' } });
  expect(submit).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Defective quantity/), { target: { value: '0' } });
  expect(screen.getByRole('status')).toHaveTextContent('PASS');
  expect(submit).toBeEnabled();
});
it('shows five required explanations for five defective pieces', () => {
  panel({ ...source, sampling_basis: 'piece', source_uom_code: 'PCS', batch_quantity: 500 });
  fireEvent.change(screen.getByLabelText(/Defective pieces/), { target: { value: '5' } });
  expect(screen.getAllByRole('textbox')).toHaveLength(5);
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
});
