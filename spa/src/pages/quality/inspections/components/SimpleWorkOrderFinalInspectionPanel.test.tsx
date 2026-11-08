import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import type { WorkOrderFinalInspection } from '@/types/quality-final';
import { SimpleWorkOrderFinalInspectionPanel } from './SimpleWorkOrderFinalInspectionPanel';
vi.mock('@/api/quality/inspections', () => ({ inspectionsApi: { recordBatchResult: vi.fn(), getWorkOrderFinalInspection: vi.fn(), reviewWorkOrderFinalInspection: vi.fn() } }));
const group = { work_order_id: 'wo', produced_quantity: 5, defective_quantity: 0, accepted_quantity: 0, state: 'checking', manifest: [], batches: [{ id: 'qc', inspection_number: 'QC-1', status: 'draft', stage: 'outgoing', result_version: 0, sampling_basis: 'piece', source_quantity: '5.000', source_uom_code: 'PCS', batch_quantity: 5, counted_capture: { version: 2, workflow: 'finish_first_v1', required_quantity: 5 }, measurements: [{ parameter_name: 'Diameter', evaluation_mode: 'numeric', tolerance_min: '1', tolerance_max: '2' }] }] } as unknown as WorkOrderFinalInspection;
function panel(data = group) { render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><SimpleWorkOrderFinalInspectionPanel group={data} canManage canReview /></MemoryRouter></QueryClientProvider>); }
beforeEach(() => vi.clearAllMocks());
it('submits final QC from a defect count alone with no measurement or confirmation inputs', async () => {
  vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({ ...group.batches[0], status: 'awaiting_review' });
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue(group);
  panel();
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/Defective pieces/), { target: { value: '0' } });
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('qc', { expected_result_version: 0, quantity_result: { defective_quantity: '0', descriptions: [] } }));
});
it('shows one required explanation for each defective final piece', () => {
  panel();
  fireEvent.change(screen.getByLabelText(/Defective pieces/), { target: { value: '2' } });
  expect(screen.getAllByRole('textbox')).toHaveLength(2);
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
});
