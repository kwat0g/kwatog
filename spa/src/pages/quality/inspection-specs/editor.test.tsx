import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import InspectionSpecEditorPage from './editor';
import { usePermission } from '@/hooks/usePermission';

vi.mock('@/api/crm/products', () => ({
 productsApi: {
  list: vi.fn().mockResolvedValue({
   data: [{ id: 'product-1', part_number: 'P-100', name: 'Widget' }],
   meta: { total: 1 },
  }),
 },
}));

vi.mock('@/api/inventory/uoms', () => ({
 uomsApi: {
  list: vi.fn().mockResolvedValue([]),
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

describe('inspection spec editor accessibility', () => {
 beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(usePermission).mockReturnValue({
   can: () => true,
   canAny: () => true,
   canAll: () => true,
   isAdmin: false,
  });
 });

 it('names parameter controls, exposes notes, scrolls the wide table, and explains missing SPC data', async () => {
  const container = renderEditor();

  // Wait for the prefill reset() to land. useFieldArray remounts every row on
  // reset, so a node queried before then is detached by the time it is asserted.
  expect(await screen.findByDisplayValue('Inspect every batch.')).toBeInTheDocument();

  expect(screen.getByRole('textbox', { name: 'Parameter 1 name' })).toBeInTheDocument();
  expect(screen.getByRole('combobox', { name: 'Parameter 1 type' })).toBeInTheDocument();
  expect(screen.getByRole('combobox', { name: 'Parameter 1 unit of measure' })).toBeInTheDocument();
  expect(screen.getByRole('textbox', { name: 'Parameter 1 nominal value' })).toBeInTheDocument();
  expect(screen.getByRole('textbox', { name: 'Parameter 1 minimum tolerance' })).toBeInTheDocument();
  expect(screen.getByRole('textbox', { name: 'Parameter 1 maximum tolerance' })).toBeInTheDocument();
  expect(screen.getByRole('textbox', { name: 'Parameter 1 notes' })).toHaveValue('Inspect every batch.');
  expect(screen.getByRole('checkbox', { name: 'Parameter 1 critical characteristic' })).toBeChecked();
  expect(container.querySelector('.overflow-x-auto')).not.toBeNull();
  expect(await screen.findByRole('status')).toHaveTextContent('No completed inspection readings');
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
});
