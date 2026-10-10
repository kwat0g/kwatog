import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { returnCasesApi } from '@/api/returnCases';
import type { ReturnCaseRecord } from '@/types/returnCases';
import ReturnCaseDetailPage from './detail';

const permissions = vi.hoisted(() => new Set<string>());
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: (permission: string) => permissions.has(permission) }) }));
vi.mock('@/api/returnCases', () => ({ returnCasesApi: { show: vi.fn(), act: vi.fn(), resolutionOptions: vi.fn() } }));

const record: ReturnCaseRecord = {
  id: 'case-1', case_number: 'CASE-202610-0001', type: 'customer', status: 'submitted', status_label: 'Submitted',
  description: 'Two pieces have cracks.', preferred_resolution: 'redelivery', resolution: null,
  resolution_notes: null, expected_date: null, created_at: null, resolved_at: null,
  party: { id: 'customer-1', name: 'Toyota' }, owner: null,
  source: { id: 'delivery-1', kind: 'delivery', label: 'DR-202610-0001' },
  can_revise_agreement: true, events: [], attachments: [],
  lines: [{ id: 'line-1', source_delivery_item_id: 'delivery-line-1', source_po_item_id: null, source_grn_item_id: null,
    product_id: 'product-1', item_id: null, product_label: 'PC-001', item_label: null, description: 'Pivot cap', unit: 'PCS',
    expected_quantity: '498.000', received_quantity: '498.000', missing_quantity: '0.000', defective_quantity: '2.000',
    verified_missing_quantity: null, verified_defective_quantity: null, lot_number: null, serial_number: null, reason: null }],
};

beforeEach(() => {
  vi.clearAllMocks();
  permissions.clear();
  permissions.add('return_management.manage');
});

async function openCase(patch: Partial<ReturnCaseRecord> = {}, realm = 'internal') {
  vi.mocked(returnCasesApi.show).mockResolvedValue({ ...record, ...patch });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const path = realm === 'internal' ? '/return-management/cases/case-1' : '/portal/customer/problems/case-1';
  render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[path]}><Routes>
    <Route path={realm === 'internal' ? '/return-management/cases/:id' : '/portal/customer/problems/:id'} element={<ReturnCaseDetailPage />} />
  </Routes></MemoryRouter></QueryClientProvider>);
  await screen.findByRole('heading', { name: /CASE-202610-0001/ });
}

it('requires starting review before showing agreement or information actions', async () => {
  await openCase();
  expect(screen.getByRole('button', { name: 'Start review' })).toBeEnabled();
  for (const name of ['Agree action', 'Request information', 'Reject', 'Create return request', 'Resolve case', 'Back', 'Open RMAs']) {
    expect(screen.queryByRole('button', { name })).not.toBeInTheDocument();
  }
});

it('advances available actions only after a successful review response', async () => {
  vi.mocked(returnCasesApi.act).mockResolvedValue({ ...record, status: 'under_review', status_label: 'Under review' });
  await openCase();
  vi.mocked(returnCasesApi.show).mockResolvedValue({ ...record, status: 'under_review', status_label: 'Under review' });
  fireEvent.click(screen.getByRole('button', { name: 'Start review' }));
  await waitFor(() => expect(returnCasesApi.act).toHaveBeenCalledWith('internal', 'case-1', { action: 'start_review' }));
  expect(await screen.findByRole('button', { name: 'Agree action' })).toBeEnabled();
  expect(screen.getByRole('button', { name: 'Request information' })).toBeEnabled();
  expect(screen.getByRole('button', { name: 'Reject' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Start review' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Resolve case' })).not.toBeInTheDocument();
});

it('keeps the submitted stage when starting review fails', async () => {
  vi.mocked(returnCasesApi.act).mockRejectedValue(new Error('Offline'));
  await openCase();
  fireEvent.click(screen.getByRole('button', { name: 'Start review' }));
  await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Start review' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Agree action' })).not.toBeInTheDocument();
});

it('waits for information or resumed review before allowing agreement', async () => {
  await openCase({ status: 'information_needed', status_label: 'Information needed' });
  expect(screen.getByRole('button', { name: 'Resume review' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Agree action' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Request information' })).not.toBeInTheDocument();
});

it('shows replacement approval only after agreement and only for an approver', async () => {
  permissions.add('return_management.approve');
  await openCase({ status: 'action_agreed', resolution: 'redelivery' });
  expect(screen.getByRole('button', { name: 'Approve no-charge replacement' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Agree action' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Resolve case' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Link completed work' })).not.toBeInTheDocument();
});

it('does not show Finance approval to Customer Service', async () => {
  await openCase({ status: 'action_agreed', resolution: 'redelivery' });
  expect(screen.queryByRole('button', { name: 'Approve no-charge replacement' })).not.toBeInTheDocument();
});

it('tracks replacements automatically without premature resolution or repeated document links', async () => {
  await openCase({ status: 'in_progress', resolution: 'redelivery', can_revise_agreement: false,
    replacement_order: { id: 'so-1', number: 'SO-REPLACEMENT', status: 'confirmed', type: 'sales_order' } });
  expect(screen.queryByRole('button', { name: 'Resolve case' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Agree action' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Approve no-charge replacement' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Link completed work' })).not.toBeInTheDocument();
  expect(screen.getByText(/PPC reviews automatic planning/)).toBeInTheDocument();
});

it('keeps reviewed no-action decisions closable without creating operational work', async () => {
  await openCase({ status: 'action_agreed', resolution: 'no_action' });
  expect(screen.getByRole('button', { name: 'Resolve case' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Create return request' })).not.toBeInTheDocument();
});

it('offers one planning action for an existing approved draft replacement', async () => {
  permissions.add('return_management.approve');
  await openCase({ status: 'in_progress', resolution: 'redelivery',
    replacement_order: { id: 'so-1', number: 'SO-REPLACEMENT', status: 'draft', type: 'sales_order' } });
  fireEvent.click(screen.getByRole('button', { name: 'Start replacement planning' }));
  const dialog = screen.getByRole('dialog', { name: 'Start replacement planning' });
  expect(within(dialog).getByRole('button', { name: 'Start replacement planning' })).toBeEnabled();
  expect(returnCasesApi.act).not.toHaveBeenCalled();
  expect(screen.queryByRole('button', { name: 'Resolve case' })).not.toBeInTheDocument();
});

it('keeps collection optional and outside primary replacement actions', async () => {
  await openCase({ status: 'in_progress', resolution: 'redelivery',
    lines: record.lines?.map(line => ({ ...line, verified_defective_quantity: '2.000' })) });
  expect(screen.getByText('Physical collection (optional)').closest('details')).not.toHaveAttribute('open');
  fireEvent.click(screen.getByText('Physical collection (optional)'));
  expect(screen.getByRole('button', { name: 'Create return request' })).toBeEnabled();
});

it('names customer receipt as the next step after driver delivery', async () => {
  await openCase({ status: 'in_progress', resolution: 'redelivery',
    replacement_order: { id: 'so-1', number: 'SO-REPLACEMENT', status: 'delivered', type: 'sales_order' },
    replacement_delivery: { id: 'dr-1', delivery_number: 'DR-REPLACEMENT', status: 'delivered' } });
  expect(screen.getByText(/Customer confirms receipt of the replacement shipment/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Resolve case' })).not.toBeInTheDocument();
});

it('shows reopening only for rejected cases', async () => {
  await openCase({ status: 'rejected' });
  expect(screen.getByRole('button', { name: 'Reopen review' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Agree action' })).not.toBeInTheDocument();
});

it.each(['resolved', 'withdrawn'] as const)('shows no workflow actions for %s cases', async (status) => {
  await openCase({ status });
  expect(screen.getByRole('group', { name: 'Case actions' }).querySelector('button')).toBeNull();
});

it('keeps shipment tracking separate from goods settlement', async () => {
  await openCase({ intake_kind: 'delivery_trace', status: 'under_review', can_resolve_trace: true });
  expect(screen.getByRole('button', { name: 'Close shipment tracking' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Agree action' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Resolve case' })).not.toBeInTheDocument();
});

it('does not expose internal workflow actions in the customer portal', async () => {
  await openCase({}, 'customer');
  for (const name of ['Start review', 'Agree action', 'Request information', 'Reject', 'Resolve case']) {
    expect(screen.queryByRole('button', { name })).not.toBeInTheDocument();
  }
  expect(screen.getByRole('button', { name: 'Withdraw report' })).toBeEnabled();
});

it('opens agreement in a labelled modal and retains its draft after cancellation', async () => {
  await openCase({ status: 'under_review' });
  const trigger = screen.getByRole('button', { name: 'Agree action' });
  fireEvent.click(trigger);
  const dialog = screen.getByRole('dialog', { name: 'Agree an action' });
  expect(dialog).toHaveAttribute('aria-modal', 'true');
  fireEvent.change(within(dialog).getByLabelText(/Agreed action and next step/), { target: { value: 'Replace 2 defective pieces.' } });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(returnCasesApi.act).not.toHaveBeenCalled();
  fireEvent.click(trigger);
  expect(within(screen.getByRole('dialog')).getByLabelText(/Agreed action and next step/)).toHaveValue('Replace 2 defective pieces.');
});

it('keeps agreement open during submission, then closes after saving the existing payload', async () => {
  let complete: (result: ReturnCaseRecord) => void = () => {};
  vi.mocked(returnCasesApi.act).mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  await openCase({ status: 'under_review' });
  fireEvent.click(screen.getByRole('button', { name: 'Agree action' }));
  const dialog = screen.getByRole('dialog', { name: 'Agree an action' });
  fireEvent.change(within(dialog).getByLabelText(/Agreed action and next step/), { target: { value: 'Replace 2 defective pieces.' } });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record agreement' }));
  await waitFor(() => expect(returnCasesApi.act).toHaveBeenCalledWith('internal', 'case-1', {
    action: 'agree', resolution: 'redelivery', message: 'Replace 2 defective pieces.', expected_date: undefined,
    lines: [{ id: 'line-1', verified_missing_quantity: '0', verified_defective_quantity: '2.000' }],
  }));
  expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled();
  expect(within(dialog).getByLabelText(/Agreed action and next step/)).toBeDisabled();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.getByRole('dialog')).toBeInTheDocument();
  const saved: ReturnCaseRecord = { ...record, status: 'action_agreed', resolution: 'redelivery' };
  vi.mocked(returnCasesApi.show).mockResolvedValue(saved);
  complete(saved);
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
});

it('shows a failed agreement inside the modal without losing entered values', async () => {
  vi.mocked(returnCasesApi.act).mockRejectedValue({ response: { data: { message: 'Verified quantities cannot exceed the reported quantities.' } } });
  await openCase({ status: 'under_review' });
  fireEvent.click(screen.getByRole('button', { name: 'Agree action' }));
  const dialog = screen.getByRole('dialog', { name: 'Agree an action' });
  const message = within(dialog).getByLabelText(/Agreed action and next step/);
  fireEvent.change(message, { target: { value: 'Replace 2 defective pieces.' } });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record agreement' }));
  expect(await within(dialog).findByRole('alert')).toHaveTextContent('Verified quantities cannot exceed');
  expect(message).toHaveValue('Replace 2 defective pieces.');
  expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeEnabled();
});

it('keeps the other action editors inline', async () => {
  await openCase({ status: 'under_review' });
  fireEvent.click(screen.getByRole('button', { name: 'Request information' }));
  expect(screen.getByLabelText(/What information is needed/)).toBeInTheDocument();
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

async function openAgreement(lines = record.lines) {
  await openCase({ status: 'under_review', lines });
  fireEvent.click(screen.getByRole('button', { name: 'Agree action' }));
  const dialog = screen.getByRole('dialog', { name: 'Agree an action' });
  fireEvent.change(within(dialog).getByLabelText(/Agreed action and next step/), { target: { value: 'Replace affected pieces.' } });
  return dialog;
}

it('hides verified missing for a damaged-only report and preserves its zero in the payload', async () => {
  const dialog = await openAgreement();
  expect(within(dialog).queryByLabelText(/Verified missing/)).not.toBeInTheDocument();
  expect(within(dialog).getByLabelText(/Verified damaged/)).toHaveValue('2.000');
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeEnabled();
});

it('hides verified damaged for a missing-only report and shows both for mixed reports', async () => {
  const dialog = await openAgreement(record.lines?.map(line => ({ ...line, received_quantity: '496.000', missing_quantity: '2.000', defective_quantity: '0.000' })));
  expect(within(dialog).queryByLabelText(/Verified damaged/)).not.toBeInTheDocument();
  expect(within(dialog).getByLabelText(/Verified missing/)).toHaveValue('2.000');
});

it('shows both reported categories and blocks incompatible return-only decisions', async () => {
  const dialog = await openAgreement(record.lines?.map(line => ({ ...line, received_quantity: '497.000', missing_quantity: '1.000' })));
  expect(within(dialog).getByLabelText(/Verified missing/)).toBeInTheDocument();
  expect(within(dialog).getByLabelText(/Verified damaged/)).toBeInTheDocument();
  fireEvent.change(within(dialog).getByLabelText('Resolution'), { target: { value: 'return_goods' } });
  expect(within(dialog).getByText('Choose redelivery or credit for verified missing goods.')).toHaveClass('text-danger-fg');
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeDisabled();
});

it.each(['3', '323232', '-1', '1e2', '0.0001', '', '999999999999999999'])('blocks invalid verified damaged quantity %s before any request', async value => {
  const dialog = await openAgreement();
  const quantity = within(dialog).getByLabelText(/Verified damaged/);
  fireEvent.change(quantity, { target: { value } });
  expect(quantity).toHaveAttribute('aria-invalid', 'true');
  expect(within(dialog).getByRole('alert')).toHaveClass('text-danger-fg');
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeDisabled();
  fireEvent.submit(within(dialog).getByRole('button', { name: 'Record agreement' }).closest('form')!);
  expect(returnCasesApi.act).not.toHaveBeenCalled();
  fireEvent.change(quantity, { target: { value: '2' } });
  expect(quantity).toHaveAttribute('aria-invalid', 'false');
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeEnabled();
});

it('requires a reviewed no-action decision for zero verified quantities', async () => {
  const dialog = await openAgreement();
  fireEvent.change(within(dialog).getByLabelText(/Verified damaged/), { target: { value: '0' } });
  expect(within(dialog).getByText('Verify an affected quantity or choose No action.')).toHaveClass('text-danger-fg');
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeDisabled();
  fireEvent.change(within(dialog).getByLabelText('Resolution'), { target: { value: 'no_action' } });
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeEnabled();
});

it('preserves exact fractional verification while blocking unrepresentable replacements', async () => {
  const dialog = await openAgreement();
  fireEvent.change(within(dialog).getByLabelText(/Verified damaged/), { target: { value: '0.001' } });
  expect(within(dialog).getByText('Replacement quantities support up to 2 decimal places. Choose another resolution without rounding.')).toHaveClass('text-danger-fg');
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeDisabled();
  fireEvent.change(within(dialog).getByLabelText('Resolution'), { target: { value: 'credit' } });
  expect(within(dialog).getByRole('button', { name: 'Record agreement' })).toBeEnabled();
});

const settlementActions = [
  { action: 'create_return', label: 'Create return request' },
  { action: 'create_replacement', label: 'Approve no-charge replacement' },
] as const;

async function openSettlement(label: string) {
  permissions.add('return_management.approve');
  await openCase({ status: 'action_agreed', resolution: 'redelivery',
    lines: record.lines?.map(line => ({ ...line, verified_defective_quantity: '2.000', verified_missing_quantity: '0.000' })) });
  if (label === 'Create return request') fireEvent.click(screen.getByText('Physical collection (optional)'));
  fireEvent.click(screen.getByRole('button', { name: label }));
  return screen.getByRole('dialog', { name: label });
}

it.each(settlementActions)('opens $label as a modal without performing the action', async ({ label }) => {
  const dialog = await openSettlement(label);
  expect(dialog).toHaveAttribute('aria-modal', 'true');
  expect(within(dialog).getByText('CASE-202610-0001 · DR-202610-0001')).toBeInTheDocument();
  expect(returnCasesApi.act).not.toHaveBeenCalled();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(returnCasesApi.act).not.toHaveBeenCalled();
});

it.each(settlementActions)('protects $label while saving and closes after success', async ({ action, label }) => {
  let complete: (result: ReturnCaseRecord) => void = () => {};
  vi.mocked(returnCasesApi.act).mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const dialog = await openSettlement(label);
  const submit = within(dialog).getByRole('button', { name: label });
  fireEvent.click(submit);
  await waitFor(() => expect(returnCasesApi.act).toHaveBeenCalledExactlyOnceWith('internal', 'case-1', { action }));
  expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled();
  expect(submit).toBeDisabled();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.getByRole('dialog', { name: label })).toBeInTheDocument();
  const saved: ReturnCaseRecord = { ...record, status: 'in_progress', resolution: 'redelivery' };
  vi.mocked(returnCasesApi.show).mockResolvedValue(saved);
  complete(saved);
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
});

it.each(settlementActions)('keeps a failed $label open with a recoverable error', async ({ label }) => {
  vi.mocked(returnCasesApi.act).mockRejectedValue({ response: { data: { message: 'Case changed. Reload and review linked work.' } } });
  const dialog = await openSettlement(label);
  fireEvent.click(within(dialog).getByRole('button', { name: label }));
  expect(await within(dialog).findByRole('alert')).toHaveTextContent('Case changed. Reload and review linked work.');
  expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeEnabled();
});
