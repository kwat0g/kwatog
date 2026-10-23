import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import InspectionSpecEditorPage from './editor';
import { usePermission } from '@/hooks/usePermission';
import { inspectionSpecsApi } from '@/api/quality/inspectionSpecs';

vi.mock('@/api/crm/products', () => ({
 productsApi: {
  options: vi.fn().mockResolvedValue({
   data: [{ id: 'product-1', part_number: 'P-100', name: 'Widget' }],
   meta: { total: 1 },
  }),
 },
}));

vi.mock('@/api/inventory/uoms', () => ({
 uomsApi: {
  list: vi.fn().mockResolvedValue([{ id: 'uom-1', code: 'KG', name: 'Kilogram', deleted_at: null }]),
 },
}));

vi.mock('@/api/quality/capability', () => ({
 capabilityApi: {
  options: vi.fn().mockResolvedValue({
   capability_thresholds: {
    launch: 1.67,
    ongoing: 1.33,
    action: 1,
    minimum_samples: 5,
   },
  }),
 },
}));

vi.mock('@/api/quality/inspectionSpecs', () => ({
 inspectionSpecsApi: {
  options: vi.fn().mockResolvedValue({
   checklist_capture_enabled: true,
   parameter_types: [
    { value: 'dimensional', label: 'Dimensional' },
    { value: 'visual', label: 'Visual' },
    { value: 'functional', label: 'Functional' },
   ],
  }),
  forProduct: vi.fn().mockResolvedValue({
   id: 'spec-1',
   version: 2,
   is_active: true,
   notes: null,
   item_count: 1,
   product: { id: 'product-1', part_number: 'P-100', name: 'Widget' },
   items: [{
    id: 'item-1',
    parameter_name: 'Offset',
    parameter_type: 'dimensional',
    unit_of_measure: 'mm',
    nominal_value: '-1.0000',
    tolerance_min: '-2.0000',
    tolerance_max: '0.0000',
    is_critical: true,
    sort_order: 0,
    notes: 'Inspect every batch.',
   }],
   created_at: '2026-08-25T00:00:00Z',
   updated_at: '2026-08-25T00:00:00Z',
  }),
  show: vi.fn(),
  revisions: vi.fn().mockResolvedValue([{
   id: 'revision-2',
   version: 2,
   is_current: true,
   notes: 'Current revision',
   creator: { id: 'user-1', name: 'Quality Manager' },
   items: [{
    id: 'item-2',
    parameter_name: 'Offset',
    parameter_type: 'dimensional',
    unit_of_measure: 'mm',
    nominal_value: '-1.0000',
    tolerance_min: '-2.0000',
    tolerance_max: '0.0000',
    is_critical: true,
    sort_order: 0,
    notes: 'Inspect every batch.',
   }],
   created_at: '2026-08-25T00:00:00Z',
   updated_at: '2026-08-25T00:00:00Z',
  }]),
  spc: vi.fn().mockResolvedValue({ data: {} }),
  upsert: vi.fn(),
  restore: vi.fn(),
 },
}));

vi.mock('@/hooks/usePermission', () => ({
 usePermission: vi.fn(),
}));

function renderEditor(): HTMLElement {
 const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
 });
 const result = render(
  <QueryClientProvider client={queryClient}>
   <MemoryRouter initialEntries={['/quality/inspection-specs/product-1']}>
    <Routes>
     <Route path="/quality/inspection-specs/:productId" element={<InspectionSpecEditorPage />} />
    </Routes>
   </MemoryRouter>
  </QueryClientProvider>,
 );
 return result.container;
}

function renderSpecDetail(): HTMLElement {
 const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
 });
 const result = render(
  <QueryClientProvider client={queryClient}>
   <MemoryRouter initialEntries={['/quality/inspection-specs/spec/spec-1']}>
    <Routes>
     <Route path="/quality/inspection-specs/spec/:specId" element={<InspectionSpecEditorPage />} />
    </Routes>
   </MemoryRouter>
  </QueryClientProvider>,
 );
 return result.container;
}

async function editExistingCheck() {
 fireEvent.click(await screen.findByRole('button', { name: 'Edit Offset' }));
}

async function editPrefilledChecks() {
 await screen.findByRole('heading', { name: 'Packaging', level: 3 });
 screen.getAllByRole('button', { name: /^Edit / }).forEach(button => fireEvent.click(button));
}

describe('inspection spec editor accessibility', () => {
 beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(usePermission).mockReturnValue({
   can: () => true,
   canApprove: () => true,
   canAny: () => true,
   canAll: () => true,
   isAdmin: false,
  });
 });

 it('preserves specification limits in the editor', async () => {
  renderEditor();
  await editExistingCheck();
  expect(await screen.findByRole('textbox', { name: 'Custom check name 1' })).toHaveValue('Offset');
  expect(screen.getByRole('textbox', { name: 'PASS requirement 1' })).toHaveValue('');
  expect(screen.getByRole('combobox', { name: 'Importance 1' })).toHaveValue('critical');
  expect(screen.getByRole('textbox', { name: 'Target 1' })).toHaveValue('-1.0000');
  expect(screen.getByRole('textbox', { name: 'Lowest allowed 1' })).toHaveValue('-2.0000');
  expect(screen.getByRole('textbox', { name: 'Highest allowed 1' })).toHaveValue('0.0000');
  expect(screen.getByRole('combobox', { name: 'Unit 1' })).toHaveValue('mm');
  vi.mocked(inspectionSpecsApi.upsert).mockResolvedValue(await inspectionSpecsApi.forProduct('product-1') as never);
  fireEvent.click(screen.getByRole('button', { name: /publish|save/i }));
  await waitFor(() => expect(inspectionSpecsApi.upsert).toHaveBeenCalledWith(expect.objectContaining({
   capture_method: 'checklist', items: [expect.objectContaining({ nominal_value: '-1.0000', tolerance_min: '-2.0000', tolerance_max: '0.0000' })],
  })));
 });

 it('renders revision history as a read-only reconstruction surface', async () => {
  vi.mocked((await import('@/api/quality/inspectionSpecs')).inspectionSpecsApi.show).mockResolvedValue({
   id: 'spec-1',
   version: 2,
   is_active: true,
   notes: 'Current revision',
   item_count: 1,
   product: { id: 'product-1', part_number: 'P-100', name: 'Widget' },
   items: [],
   created_at: '2026-08-25T00:00:00Z',
   updated_at: '2026-08-25T00:00:00Z',
  });
  renderSpecDetail();

  expect(await screen.findByRole('combobox', { name: 'Revision to inspect' })).toBeInTheDocument();
  expect(screen.getByRole('region', { name: 'Inspection spec revision 2' })).toBeInTheDocument();
 });

 it('preserves edits when parameter metadata loads after the spec', async () => {
  let resolveOptions!: (value: {
   parameter_types: Array<{ value: string; label: string }>;
  }) => void;
  const options = new Promise<{ parameter_types: Array<{ value: string; label: string }> }>(
   (resolve) => { resolveOptions = resolve; },
  );
  vi.mocked(inspectionSpecsApi.options).mockReturnValueOnce(options as never);

  renderEditor();
  await editExistingCheck();

  await waitFor(() =>
   expect(screen.getByRole('textbox', { name: 'PASS requirement 1' })).toHaveValue(''),
  );
  fireEvent.change(screen.getByRole('textbox', { name: 'PASS requirement 1' }), {
   target: { value: 'Edited while parameter metadata loads.' },
  });
  resolveOptions({
   parameter_types: [
    { value: 'dimensional', label: 'Dimensional' },
    { value: 'visual', label: 'Visual' },
   ],
  });
  await options;
  await waitFor(() => expect(inspectionSpecsApi.options).toHaveBeenCalled());
  await act(async () => new Promise((resolve) => setTimeout(resolve, 0)));

  expect(screen.getByRole('textbox', { name: 'PASS requirement 1' })).toHaveValue(
   'Edited while parameter metadata loads.',
  );
 });

 it('prefills five product checks without inventing limits or publishing automatically', async () => {
  vi.mocked(inspectionSpecsApi.forProduct).mockResolvedValueOnce(null);
  renderEditor();
  await editPrefilledChecks();
  expect(screen.getByRole('combobox', { name: 'What to check 5' })).toHaveValue('Packaging');
  for (const [index, name] of ['Product identity', 'Appearance', 'Size', 'Fit / function', 'Packaging'].entries()) {
   expect(screen.getByRole('combobox', { name: `What to check ${index + 1}` })).toHaveValue(name);
   expect(screen.getByRole('textbox', { name: `PASS requirement ${index + 1}` })).not.toHaveValue('');
  }
  expect(screen.getByRole('textbox', { name: 'PASS requirement 1' })).toHaveValue('Product matches P-100 — Widget and its approved identification.');
  expect(screen.getByRole('combobox', { name: 'How to check 2' })).toHaveValue('visual');
  expect(screen.getByRole('textbox', { name: 'Target 3' })).toHaveValue('');
  expect(screen.getByRole('textbox', { name: 'Lowest allowed 3' })).toHaveValue('');
  expect(screen.getByRole('textbox', { name: 'Highest allowed 3' })).toHaveValue('');
  expect(screen.getByRole('combobox', { name: 'Unit 3' })).toHaveValue('');
  expect(inspectionSpecsApi.upsert).not.toHaveBeenCalled();
  fireEvent.change(screen.getByRole('textbox', { name: 'PASS requirement 2' }), { target: { value: '' } });
  fireEvent.click(screen.getByRole('button', { name: 'Create spec' }));
  expect(await screen.findByText('Enter the PASS requirement or approved reference.')).toBeVisible();
  expect(inspectionSpecsApi.upsert).not.toHaveBeenCalled();
  fireEvent.change(screen.getByRole('textbox', { name: 'PASS requirement 2' }), { target: { value: 'No cracks, flash or short shots.' } });
  fireEvent.change(screen.getByRole('combobox', { name: 'Importance 2' }), { target: { value: 'critical' } });
  fireEvent.change(screen.getByRole('combobox', { name: 'Unit 3' }), { target: { value: 'mm' } });
  for (const [label, value] of [['Target 3', '10'], ['Lowest allowed 3', '9.9'], ['Highest allowed 3', '10.1']]) fireEvent.change(screen.getByRole('textbox', { name: label }), { target: { value } });
  vi.mocked(inspectionSpecsApi.upsert).mockResolvedValue({ id: 'spec-new', version: 1 } as never);
  fireEvent.click(screen.getByRole('button', { name: 'Create spec' }));
  await waitFor(() => expect(inspectionSpecsApi.upsert).toHaveBeenCalledWith(expect.objectContaining({
   capture_method: 'checklist', items: expect.arrayContaining([expect.objectContaining({ parameter_name: 'Appearance', parameter_type: 'visual', acceptance_criterion: 'No cracks, flash or short shots.', is_critical: true, nominal_value: undefined })]),
  })));
  expect(vi.mocked(inspectionSpecsApi.upsert).mock.calls[0][0].items).toHaveLength(5);
 });

 it('requires unit and all size dimensions despite a written reference', async () => {
  vi.mocked(inspectionSpecsApi.forProduct).mockResolvedValueOnce(null);
  renderEditor();
  await editPrefilledChecks();
  fireEvent.click(screen.getByRole('button', { name: 'Create spec' }));
  expect(await screen.findByText('Choose a measurement unit.')).toBeVisible();
  expect(screen.getByText('Enter the measurement target.')).toBeVisible();
  expect(await screen.findByText('Enter the lowest allowed value.')).toBeVisible();
  expect(screen.getByText('Enter the highest allowed value.')).toBeVisible();
  expect(inspectionSpecsApi.upsert).not.toHaveBeenCalled();
  for (const [label, value] of [['Target 3', '10'], ['Lowest allowed 3', '9.9'], ['Highest allowed 3', '10.1']]) fireEvent.change(screen.getByRole('textbox', { name: label }), { target: { value } });
  fireEvent.click(screen.getByRole('button', { name: 'Create spec' }));
  expect(await screen.findByText('Choose a measurement unit.')).toBeVisible();
  expect(inspectionSpecsApi.upsert).not.toHaveBeenCalled();
  fireEvent.change(screen.getByRole('combobox', { name: 'Unit 3' }), { target: { value: 'mm' } });
  vi.mocked(inspectionSpecsApi.upsert).mockResolvedValue({ id: 'spec-new', version: 1 } as never);
  fireEvent.click(screen.getByRole('button', { name: 'Create spec' }));
  await waitFor(() => expect(inspectionSpecsApi.upsert).toHaveBeenCalledOnce());
  expect(vi.mocked(inspectionSpecsApi.upsert).mock.calls[0][0].items[2]).toMatchObject({ parameter_type: 'dimensional', unit_of_measure: 'mm', nominal_value: '10', tolerance_min: '9.9', tolerance_max: '10.1' });
 });

 it('hides Add check when all standard checks exist and disables duplicate choices', async () => {
  vi.mocked(inspectionSpecsApi.forProduct).mockResolvedValueOnce(null);
  renderEditor();
  await editPrefilledChecks();
  expect(screen.queryByRole('button', { name: 'Add check' })).not.toBeInTheDocument();
  const choices = screen.getByRole('combobox', { name: 'What to check 1' });
  expect(choices.querySelector('option[value="Appearance"]')).toBeDisabled();
  expect(choices.querySelector('option[value="Product identity"]')).not.toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Remove check 5' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add check' }));
  fireEvent.change(screen.getByRole('combobox', { name: 'What to check 5' }), { target: { value: 'Packaging' } });
  expect(screen.queryByRole('button', { name: 'Add check' })).not.toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Packaging', level: 3 })).toBeVisible();
 });

 it('prefills a newly selected check without replacing manual instructions', async () => {
  renderEditor();
  await editExistingCheck();
  fireEvent.click(screen.getByRole('button', { name: 'Add check' }));
  fireEvent.change(screen.getByRole('combobox', { name: 'What to check 2' }), { target: { value: 'Appearance' } });
  expect(screen.getByRole('textbox', { name: 'PASS requirement 2' })).toHaveValue('No cracks, damage, contamination, flash or short shots.');
  fireEvent.change(screen.getByRole('textbox', { name: 'PASS requirement 2' }), { target: { value: 'Keep the approved surface instructions.' } });
  fireEvent.change(screen.getByRole('combobox', { name: 'What to check 2' }), { target: { value: 'Packaging' } });
  expect(screen.getByRole('textbox', { name: 'PASS requirement 2' })).toHaveValue('Keep the approved surface instructions.');
 });

 it('keeps prefilled requirements readable and saves fields while their editors are collapsed', async () => {
  vi.mocked(inspectionSpecsApi.forProduct).mockResolvedValueOnce(null);
  renderEditor();
  await screen.findByRole('heading', { name: 'Appearance', level: 3 });
  expect(screen.queryByRole('combobox', { name: 'What to check 2' })).not.toBeInTheDocument();
  expect(screen.getByText('No cracks, damage, contamination, flash or short shots.')).toBeVisible();
  expect(screen.getByRole('textbox', { name: 'Target 3' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Edit Appearance' })).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(screen.getByRole('button', { name: 'Edit Appearance' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'PASS requirement 2' }), { target: { value: 'No cracks on any surface.' } });
  fireEvent.click(screen.getByRole('button', { name: 'Done editing Appearance' }));
  expect(screen.getByText('No cracks on any surface.')).toBeVisible();
  expect(screen.queryByRole('textbox', { name: 'PASS requirement 2' })).not.toBeInTheDocument();
  fireEvent.change(screen.getByRole('combobox', { name: 'Unit 3' }), { target: { value: 'mm' } });
  for (const [label, value] of [['Target 3', '10'], ['Lowest allowed 3', '9.9'], ['Highest allowed 3', '10.1']]) fireEvent.change(screen.getByRole('textbox', { name: label }), { target: { value } });
  vi.mocked(inspectionSpecsApi.upsert).mockResolvedValue({ id: 'spec-new', version: 1 } as never);
  fireEvent.click(screen.getByRole('button', { name: 'Create spec' }));
  await waitFor(() => expect(inspectionSpecsApi.upsert).toHaveBeenCalledOnce());
  const payload = vi.mocked(inspectionSpecsApi.upsert).mock.calls[0][0];
  expect(payload.items).toHaveLength(5);
  expect(payload.items[1]).toMatchObject({ parameter_name: 'Appearance', parameter_type: 'visual', acceptance_criterion: 'No cracks on any surface.' });
 });

 it('reveals a collapsed check when its requirement needs correction', async () => {
  vi.mocked(inspectionSpecsApi.forProduct).mockResolvedValueOnce(null);
  renderEditor();
  fireEvent.click(await screen.findByRole('button', { name: 'Edit Appearance' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'PASS requirement 2' }), { target: { value: '' } });
  fireEvent.click(screen.getByRole('button', { name: 'Done editing Appearance' }));
  fireEvent.click(screen.getByRole('button', { name: 'Create spec' }));
  expect(await screen.findByText('Enter the PASS requirement or approved reference.')).toBeVisible();
  expect(screen.getByRole('textbox', { name: 'PASS requirement 2' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Done editing Appearance' })).toHaveAttribute('aria-expanded', 'true');
  expect(inspectionSpecsApi.upsert).not.toHaveBeenCalled();
 });

 it('allows viewing requirements without exposing authoring actions to view-only staff', async () => {
  vi.mocked(usePermission).mockReturnValue({ can: permission => permission === 'quality.specs.view', canApprove: () => false, canAny: () => false, canAll: () => false, isAdmin: false });
  renderEditor();
  expect(await screen.findByRole('heading', { name: 'Offset', level: 3 })).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Edit Offset' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Save new version' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Remove check 1' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'View Offset' }));
  expect(screen.getByRole('textbox', { name: 'PASS requirement 1' })).toHaveAttribute('readonly');
  expect(screen.getByRole('textbox', { name: 'Target 1' })).toHaveAttribute('readonly');
  expect(screen.getByRole('combobox', { name: 'Importance 1' })).toBeDisabled();
 });

 it('keeps custom names and limits when inspection method changes', async () => {
  renderEditor();
  await editExistingCheck();
  fireEvent.change(screen.getByRole('combobox', { name: 'How to check 1' }), { target: { value: 'visual' } });
  expect(screen.getByRole('textbox', { name: 'Target 1' })).toHaveValue('-1.0000');
  fireEvent.click(screen.getByRole('button', { name: 'Save new version' }));
  expect(await screen.findByText('Visual checks cannot have numeric limits.')).toBeVisible();
  expect(inspectionSpecsApi.upsert).not.toHaveBeenCalled();
  fireEvent.change(screen.getByRole('combobox', { name: 'How to check 1' }), { target: { value: 'dimensional' } });
  expect(screen.getByRole('textbox', { name: 'Custom check name 1' })).toHaveValue('Offset');
  expect(screen.getByRole('textbox', { name: 'Lowest allowed 1' })).toHaveValue('-2.0000');
 });
});
