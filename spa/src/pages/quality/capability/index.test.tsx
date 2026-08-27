import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CapabilityStudyPage from './index';
import { capabilityApi } from '@/api/quality/capability';
import { inspectionSpecsApi } from '@/api/quality/inspectionSpecs';
import { productsApi } from '@/api/crm/products';

vi.mock('@/api/quality/capability', () => ({
 capabilityApi: {
  options: vi.fn(),
  runCapability: vi.fn(),
 },
}));

vi.mock('@/api/quality/inspectionSpecs', () => ({
 inspectionSpecsApi: {
  forProduct: vi.fn(),
 },
}));

vi.mock('@/api/crm/products', () => ({
 productsApi: {
  list: vi.fn(),
 },
}));

const studyResult = {
 cp: 1.82,
 cpk: 1.41,
 cpu: 1.55,
 cpl: 1.41,
 mean: 10.0123,
 std_dev: 0.0912,
 sample_count: 12,
 usl: 10.5,
 lsl: 9.5,
 histogram: {
  bins: [2, 5, 4, 1],
  bin_edges: [9.5, 9.75, 10, 10.25, 10.5],
  lsl: 9.5,
  usl: 10.5,
 },
};

function renderPage() {
 const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
 });

 return render(
  <QueryClientProvider client={queryClient}>
   <MemoryRouter>
    <CapabilityStudyPage />
   </MemoryRouter>
  </QueryClientProvider>,
 );
}

describe('CapabilityStudyPage', () => {
 beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(productsApi.list).mockResolvedValue({
   data: [{ id: 'product-1', part_number: 'P-100', name: 'Widget' }],
   meta: { total: 1 },
  } as never);
  vi.mocked(inspectionSpecsApi.forProduct).mockResolvedValue({
   id: 'spec-1',
   items: [{
    id: 'item-1',
    parameter_name: 'Diameter',
    unit_of_measure: 'mm',
    tolerance_min: '9.5',
    tolerance_max: '10.5',
   }],
  } as never);
  vi.mocked(capabilityApi.options).mockRejectedValue(new Error('options unavailable'));
  vi.mocked(capabilityApi.runCapability).mockResolvedValue(studyResult as never);
 });

 it('keeps a successful study visible without thresholds and offers options retry feedback', async () => {
  renderPage();

  expect(await screen.findByText(/could not load capability thresholds/i)).toBeInTheDocument();
  const retry = screen.getByRole('button', { name: /try again/i });
  fireEvent.click(retry);
  await waitFor(() => expect(capabilityApi.options).toHaveBeenCalledTimes(2));

  fireEvent.change(screen.getByRole('combobox', { name: 'Product' }), {
   target: { value: 'product-1' },
  });
  const dimension = await screen.findByRole('combobox', { name: 'Dimension (spec item)' });
  await waitFor(() => expect(dimension).not.toBeDisabled());
  fireEvent.change(dimension, { target: { value: 'item-1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Run Study' }));

  expect(await screen.findByText('1.82')).toBeInTheDocument();
  expect(screen.getByText('1.41')).toBeInTheDocument();
  expect(screen.getByText('12')).toBeInTheDocument();
  expect(screen.getByText(/thresholds unavailable/i)).toBeInTheDocument();
  expect(screen.getByText(/could not load capability thresholds/i)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();

  expect(screen.queryByText('Excellent')).not.toBeInTheDocument();
  expect(screen.queryByText('Capable')).not.toBeInTheDocument();
  expect(screen.queryByText('Marginal')).not.toBeInTheDocument();
  expect(screen.queryByText('Not capable')).not.toBeInTheDocument();
  expect(screen.queryByText(/IATF 16949 targets/i)).not.toBeInTheDocument();
  expect(capabilityApi.runCapability).toHaveBeenCalledWith({
   product_id: 'product-1',
   spec_item_id: 'item-1',
  });
 });
});
