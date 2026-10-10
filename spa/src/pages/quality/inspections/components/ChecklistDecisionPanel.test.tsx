import { useAuthStore } from '@/stores/authStore';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection } from '@/types/quality';
import { ChecklistDecisionPanel } from './ChecklistDecisionPanel';

vi.mock('@/api/quality/inspections', () => ({ inspectionsApi: { recordBatchResult: vi.fn(), recordItemResults: vi.fn() } }));

const inspection = {
  id: 'qc-check', entity_hash_id: 'source-1', capture_method: 'checklist', status: 'draft', result_version: 0,
  sample_size: 5, batch_quantity: 100, source_quantity: '100.000', source_uom_code: 'PCS', sampling_basis: 'piece',
  aql_code: 'F', accept_count: 1, reject_count: 2,
  measurements: ['Surface', 'Color', 'Fit'].map((name, index) => ({
    id: `check-${index}`, parameter_name: name, acceptance_criterion: 'Matches approved sample.', is_critical: index === 2, item_result: null,
  })),
} as unknown as Inspection;

function panel(record = inspection) {
  return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter><ChecklistDecisionPanel inspection={record} canManage /></MemoryRouter>
  </QueryClientProvider>);
}

beforeEach(() => { vi.clearAllMocks(); vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({ ...inspection, status: 'passed', result_version: 1 }); });

it('submits one atomic request without numeric measurements or separate zero input', async () => {
  panel();
  expect(screen.getByText('AQL F: accept up to 1 defective piece; reject at 2 or more.')).toBeInTheDocument();
  expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
  for (const name of ['Surface', 'Color', 'Fit']) fireEvent.click(screen.getByRole('button', { name: `PASS ${name}` }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledTimes(1));
  expect(inspectionsApi.recordItemResults).not.toHaveBeenCalled();
  expect(vi.mocked(inspectionsApi.recordBatchResult).mock.calls[0][1]).toEqual({
    expected_result_version: 0, coverage_confirmed: true,
    items: [0, 1, 2].map(index => ({ id: `check-${index}`, result: 'passed' })),
  });
});

it('reveals failure description and physical count only when needed', () => {
  panel();
  fireEvent.click(screen.getByRole('button', { name: 'FAIL Fit' }));
  expect(screen.getByRole('textbox', { name: 'Problem for Fit' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /^Yes$/ }));
  expect(screen.getByRole('spinbutton', { name: 'Unique defective pieces' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
});

it('requires removal confirmation for physical defects within the approved acceptance count', async () => {
  panel();
  for (const name of ['Surface', 'Color', 'Fit']) fireEvent.click(screen.getByRole('button', { name: `PASS ${name}` }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'Physical batch defect outside these checks' }));
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Unique defective pieces' }), { target: { value: '1' } });
  fireEvent.change(screen.getByRole('textbox', { name: 'Batch problem description' }), { target: { value: 'One chipped piece.' } });

  const removed = screen.getByRole('checkbox', { name: 'I confirm the defective pieces were segregated before accepting this batch' });
  expect(removed).not.toBeChecked();
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.click(removed);
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));

  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledOnce());
  expect(vi.mocked(inspectionsApi.recordBatchResult).mock.calls[0][1]).toMatchObject({
    physical_defects_confirmed: true,
    sample_defect_count: 1,
    problem_description: 'One chipped piece.',
    defects_removed_confirmed: true,
  });
});

it('requires performance attestation before marking remaining checks passed', async () => {
  panel();
  fireEvent.click(screen.getByRole('button', { name: 'PASS remaining checks (3)' }));
  expect(screen.getByRole('button', { name: 'PASS Surface' })).toHaveAttribute('aria-pressed', 'false');
  fireEvent.click(screen.getByRole('button', { name: 'Checks performed; mark PASS' }));
  expect(screen.getByRole('button', { name: 'PASS Surface' })).toHaveAttribute('aria-pressed', 'true');
  expect(inspectionsApi.recordBatchResult).not.toHaveBeenCalled();
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
});

it('preserves local failure input after server rejection', async () => {
  vi.mocked(inspectionsApi.recordBatchResult).mockRejectedValue({ response: { data: { message: 'The source changed.' } } });
  panel();
  fireEvent.click(screen.getByRole('button', { name: 'FAIL Surface' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for Surface' }), { target: { value: 'Required certificate missing.' } });
  fireEvent.click(screen.getByRole('button', { name: /^No$/ }));
  for (const name of ['Color', 'Fit']) fireEvent.click(screen.getByRole('button', { name: `PASS ${name}` }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await screen.findByText('The source changed.');
  expect(screen.getByRole('textbox', { name: 'Problem for Surface' })).toHaveValue('Required certificate missing.');
});

it('locks a stale draft and discards it only after explicit reload', () => {
  const client = new QueryClient();
  const view = render(<QueryClientProvider client={client}><MemoryRouter><ChecklistDecisionPanel inspection={inspection} canManage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(screen.getByRole('button', { name: 'FAIL Surface' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for Surface' }), { target: { value: 'Local draft.' } });
  view.rerender(<QueryClientProvider client={client}><MemoryRouter><ChecklistDecisionPanel inspection={{ ...inspection, result_version: 1 }} canManage /></MemoryRouter></QueryClientProvider>);
  expect(screen.getByRole('button', { name: 'PASS Surface' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Missing document' })).toBeDisabled();
  expect(screen.getByRole('textbox', { name: 'Problem for Surface' })).toHaveValue('Local draft.');
  fireEvent.click(screen.getByRole('button', { name: 'Reload server version' }));
  expect(screen.queryByRole('textbox', { name: 'Problem for Surface' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'PASS Surface' })).toBeEnabled();
});

it('does not leave an invisible defect-count blocker after correcting a failed decision', async () => {
  panel();
  fireEvent.click(screen.getByRole('button', { name: 'FAIL Surface' }));
  fireEvent.click(screen.getByRole('button', { name: /^Yes$/ }));
  for (const name of ['Surface', 'Color', 'Fit']) fireEvent.click(screen.getByRole('button', { name: `PASS ${name}` }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
  expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledOnce());
});

it('shows specification limits as reference without measured-value inputs', () => {
  panel({ ...inspection, measurements: [{ ...inspection.measurements![0], unit_of_measure: 'mm', nominal_value: '10.0000', tolerance_min: '9.9000', tolerance_max: '10.1000' }] });
  expect(screen.getByText(/Target: 10.0000 mm/)).toBeVisible();
  expect(screen.getByText(/Lowest allowed: 9.9000 mm/)).toBeVisible();
  expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
});

it.each(['incoming', 'in_process', 'outgoing', 'supplier_return', 'customer_return'] as const)('shows approved instructions and PASS/FAIL only for %s', async stage => {
  const record = { ...inspection, stage, measurements: [{ ...inspection.measurements![0], parameter_type: 'visual' as const, notes: 'Inspect under good lighting.' }] };
  panel(record);
  expect(screen.getByText('How to check: Visual inspection')).toBeVisible();
  expect(screen.getByText('Matches approved sample.')).toBeVisible();
  expect(screen.getByText('Inspect under good lighting.')).toBeVisible();
  expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
  expect(screen.queryByRole('textbox', { name: 'Problem for Surface' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'FAIL Surface' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for Surface' }), { target: { value: 'Visible crack.' } });
  fireEvent.click(screen.getByRole('button', { name: 'No' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledOnce());
  expect(vi.mocked(inspectionsApi.recordBatchResult).mock.calls[0][1]).toMatchObject({ items: [{ id: 'check-0', result: 'failed', problem_description: 'Visible crack.' }] });
});


it('requires full-piece attestation without a redundant coverage input', async () => {
  panel({ ...inspection, sample_size: 100 });
  expect(screen.queryByRole('checkbox', { name: 'Inspected more than the planned sample' })).not.toBeInTheDocument();
  expect(screen.getByText('Check every piece; record one PASS or FAIL per requirement.')).toBeVisible();
  for (const name of ['Surface', 'Color', 'Fit']) fireEvent.click(screen.getByRole('button', { name: `PASS ${name}` }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm every piece was inspected against all required checks' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledOnce());
  expect(vi.mocked(inspectionsApi.recordBatchResult).mock.calls[0][1]).not.toHaveProperty('inspected_quantity');
});

it.each(['incoming', 'in_process', 'outgoing', 'supplier_return', 'customer_return'] as const)('records only actual size readings against pinned limits for %s', async stage => {
  const size = { ...inspection.measurements![0], id: 'size-check', parameter_name: 'Diameter', parameter_type: 'dimensional' as const, unit_of_measure: 'mm', nominal_value: '10.0000', tolerance_min: '9.9000', tolerance_max: '10.1000', measured_value: null };
  panel({ ...inspection, stage, measurements: [size, inspection.measurements![1]] });
  expect(screen.getByRole('textbox', { name: 'Actual size for Diameter' })).toHaveValue('');
  fireEvent.click(screen.getByRole('button', { name: 'PASS Color' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.change(screen.getByRole('textbox', { name: 'Actual size for Diameter' }), { target: { value: '10.1' } });
  expect(screen.getByRole('button', { name: 'PASS Diameter' })).toHaveAttribute('aria-pressed', 'true');
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledOnce());
  const payload = vi.mocked(inspectionsApi.recordBatchResult).mock.calls[0][1];
  expect('items' in payload ? payload.items : []).toEqual([
    { id: 'size-check', result: 'passed', measured_value: '10.1' }, { id: 'check-1', result: 'passed' },
  ]);
});

it('blocks invalid size input, requires failure details, and excludes size checks from bulk PASS', async () => {
  const size = { ...inspection.measurements![0], parameter_name: 'Diameter', parameter_type: 'dimensional' as const, unit_of_measure: 'mm', tolerance_min: '9.9', tolerance_max: '10.1', measured_value: null };
  panel({ ...inspection, measurements: [size, ...inspection.measurements!.slice(1)] });
  fireEvent.click(screen.getByRole('button', { name: 'PASS remaining checks (2)' }));
  fireEvent.click(screen.getByRole('button', { name: 'Checks performed; mark PASS' }));
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  expect(screen.getByRole('button', { name: 'PASS Diameter' })).toHaveAttribute('aria-pressed', 'false');
  fireEvent.change(screen.getByRole('textbox', { name: 'Actual size for Diameter' }), { target: { value: '10.10001' } });
  expect(screen.getByText('Enter a decimal with up to 4 places.')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.change(screen.getByRole('textbox', { name: 'Actual size for Diameter' }), { target: { value: '10.2' } });
  expect(screen.getByRole('button', { name: 'FAIL Diameter' })).toHaveAttribute('aria-pressed', 'true');
  expect(screen.getByRole('button', { name: 'PASS Diameter' })).toBeDisabled();
  expect(screen.getByRole('textbox', { name: 'Problem for Diameter' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
});

it('saves an actual size reading in the same draft request as its decision', async () => {
  const size = { ...inspection.measurements![0], parameter_name: 'Diameter', parameter_type: 'dimensional' as const, tolerance_min: '-2.0000', tolerance_max: '0.0000', measured_value: null };
  vi.mocked(inspectionsApi.recordItemResults).mockResolvedValue({ ...inspection, result_version: 1 });
  panel({ ...inspection, measurements: [size] });
  fireEvent.change(screen.getByRole('textbox', { name: 'Actual size for Diameter' }), { target: { value: '-1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
  await waitFor(() => expect(inspectionsApi.recordItemResults).toHaveBeenCalledWith(inspection.id, { items: [{ id: 'check-0', result: 'passed', measured_value: '-1' }], submit: false, expected_result_version: 0 }));
});

it('retains actual size and failure description after a rejected submission', async () => {
  const size = { ...inspection.measurements![0], parameter_name: 'Diameter', parameter_type: 'dimensional' as const, tolerance_min: '9.9', tolerance_max: '10.1', measured_value: null };
  vi.mocked(inspectionsApi.recordBatchResult).mockRejectedValue({ response: { data: { message: 'Source quantity changed.' } } });
  panel({ ...inspection, measurements: [size] });
  fireEvent.change(screen.getByRole('textbox', { name: 'Actual size for Diameter' }), { target: { value: '10.2' } });
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for Diameter' }), { target: { value: 'Measured oversize.' } });
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Unique defective pieces' }), { target: { value: '1' } });
  fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await screen.findByText('Source quantity changed.');
  expect(screen.getByRole('textbox', { name: 'Actual size for Diameter' })).toHaveValue('10.2');
  expect(screen.getByRole('textbox', { name: 'Problem for Diameter' })).toHaveValue('Measured oversize.');
});

it('shows locked checklist decisions as readable evidence, with no disabled entry fields', () => {
  panel({ ...inspection, status: 'failed', measurements: [{ ...inspection.measurements![0], item_result: 'failed', item_problem_description: 'Crack at edge.' }] });
  expect(screen.queryByRole('button', { name: 'PASS Surface' })).not.toBeInTheDocument();
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  expect(screen.getByText('Crack at edge.')).toBeVisible();
  expect(screen.getByText('FAIL')).toBeVisible();
});

it('reports completion progress and warns on hard navigation while dirty', () => {
  panel();
  fireEvent.click(screen.getByRole('button', { name: 'PASS Surface' }));
  expect(screen.getByText('1 of 3 checks completed')).toBeVisible();
  const event = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(event);
  expect(event.defaultPrevented).toBe(true);
});

it('restores user-scoped recovery input but never restores coverage confirmation', () => {
  useAuthStore.setState({ user: { id: 'recovery-user' } as NonNullable<ReturnType<typeof useAuthStore.getState>['user']> });
  const key = 'ogami:formdraft:recovery-user:quality-inspection:qc-check:version:0';
  localStorage.setItem(key, JSON.stringify({ ts: Date.now(), values: { draft: { 'check-0': { result: 'failed', problem: 'Crack.' } }, coverage: true, physical: true, defects: '1' } }));
  const view = panel();
  fireEvent.click(screen.getByRole('button', { name: 'Restore' }));
  expect(screen.getByRole('textbox', { name: 'Problem for Surface' })).toHaveValue('Crack.');
  expect(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' })).not.toBeChecked();
  view.unmount();
  localStorage.removeItem(key);
  useAuthStore.setState({ user: null });
});
