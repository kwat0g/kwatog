import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ItemQualityPlansPage from './quality-plans';
import { itemQualityPlansApi } from '@/api/inventory/items';

vi.mock('@/api/inventory/items', () => ({
  itemsApi: { show: vi.fn().mockResolvedValue({ id: 'item-1', code: 'RM-001' }) },
  itemQualityPlansApi: {
    list: vi.fn().mockResolvedValue([]),
    options: vi.fn().mockResolvedValue({ checklist_capture_enabled: true, sampling_methods: [{ value: 'full', label: 'Full inspection' }] }),
    createRevision: vi.fn(), deactivate: vi.fn(),
  },
}));
vi.mock('@/api/accounting/vendors', () => ({ vendorsApi: { list: vi.fn().mockResolvedValue({ data: [] }) } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

function renderPage() {
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter initialEntries={['/inventory/items/item-1/quality-plans']}><Routes>
      <Route path="/inventory/items/:id/quality-plans" element={<ItemQualityPlansPage />} />
    </Routes></MemoryRouter>
  </QueryClientProvider>);
}

describe('incoming checklist authoring', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requires written criteria and retains them after a server rejection', async () => {
    vi.mocked(itemQualityPlansApi.createRevision).mockRejectedValueOnce({ response: { status: 422, data: { errors: { 'parameters.0.acceptance_criterion': ['Use an approved inspection reference.'] } } } });
    renderPage();
    const name = await screen.findByRole('textbox', { name: 'Check 1' });
    const criterion = screen.getByRole('textbox', { name: 'Acceptance criterion / reference 1' });
    expect(screen.queryByRole('spinbutton', { name: /nominal|min|max/i })).not.toBeInTheDocument();
    fireEvent.change(screen.getByRole('combobox', { name: 'Sampling' }), { target: { value: 'full' } });
    fireEvent.change(name, { target: { value: 'Condition' } });
    expect(screen.getByRole('button', { name: 'Publish revision' })).toBeDisabled();
    fireEvent.change(criterion, { target: { value: 'No contamination.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Publish revision' }));
    await waitFor(() => expect(itemQualityPlansApi.createRevision).toHaveBeenCalledOnce());
    expect(await screen.findByText('Use an approved inspection reference.')).toBeInTheDocument();
    expect(criterion).toHaveValue('No contamination.');
    expect(itemQualityPlansApi.createRevision).toHaveBeenCalledWith('item-1', expect.objectContaining({
      capture_method: 'checklist', parameters: [{ parameter_name: 'Condition', acceptance_criterion: 'No contamination.', parameter_type: null, is_critical: false }],
    }));
  });

  it('keeps failed metadata loading distinct from an empty supplier list', async () => {
    vi.mocked(itemQualityPlansApi.options).mockRejectedValueOnce(new Error('offline'));
    renderPage();
    expect(await screen.findByText(/quality-plan options/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Publish revision' })).toBeDisabled();
    expect(itemQualityPlansApi.createRevision).not.toHaveBeenCalled();
  });
});
