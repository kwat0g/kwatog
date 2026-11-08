import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { NcrTemplate } from '@/types/quality';
import NcrTemplateFormPage from './create';

const mocks = vi.hoisted(() => ({
  show: vi.fn(),
  options: vi.fn(),
  products: vi.fn(),
  update: vi.fn(),
  realtime: vi.fn(),
}));

vi.mock('@/api/quality/ncr-templates', () => ({
  ncrTemplatesApi: {
    show: mocks.show,
    options: mocks.options,
    update: mocks.update,
    create: vi.fn(),
  },
}));
vi.mock('@/api/inventory/items', () => ({ itemsApi: { list: mocks.products } }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: mocks.realtime }));

function template(overrides: Partial<NcrTemplate> = {}): NcrTemplate {
  return {
    id: 'template-1',
    name: 'Mold flash',
    source: 'inspection_fail',
    severity: 'medium',
    defect_description: 'Excess material at the parting line',
    notes: 'Inspect the mold shutoff',
    is_active: true,
    product: null,
    created_at: '2026-11-02T01:00:00.000Z',
    updated_at: '2026-11-02T01:00:00.000Z',
    ...overrides,
  };
}

describe('NCR template edit form realtime draft safety', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.show.mockResolvedValue(template());
    mocks.options.mockResolvedValue({
      sources: [{ value: 'inspection_fail', label: 'Inspection failure' }],
      severities: [{ value: 'medium', label: 'Medium' }],
    });
    mocks.products.mockResolvedValue({ data: [], meta: { total: 0 } });
  });

  it('subscribes to the record and keeps a dirty draft when a remote update arrives', async () => {
    let onRemoteChange: ((payload?: unknown) => void) | undefined;
    mocks.realtime.mockImplementation((_entity: string, _id: string, _keys: unknown, onEvent: (payload?: unknown) => void) => {
      onRemoteChange = onEvent;
    });

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/quality/ncr-templates/template-1/edit']}>
          <Routes>
            <Route path="/quality/ncr-templates/:id/edit" element={<NcrTemplateFormPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const name = await screen.findByRole('textbox', { name: 'Template name' });
    await waitFor(() => expect(name).toHaveValue('Mold flash'));

    fireEvent.change(name, { target: { value: 'My unsaved template name' } });
    act(() => {
      onRemoteChange?.({ entity_id: 'template-1' });
      queryClient.setQueryData(
        ['quality', 'ncr-templates', 'template-1'],
        template({ name: 'Remote template name', updated_at: '2026-11-02T01:05:00.000Z' }),
      );
    });

    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Template name' })).toHaveValue('My unsaved template name'));
    expect(await screen.findByRole('status')).toHaveTextContent(/changed elsewhere/i);
    expect(mocks.realtime).toHaveBeenCalledWith('ncr_template', 'template-1', [], expect.any(Function));

    mocks.show.mockResolvedValue(template({ name: 'Remote template name', updated_at: '2026-11-02T01:05:00.000Z' }));
    fireEvent.click(screen.getByRole('button', { name: 'Load latest' }));
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Template name' })).toHaveValue('Remote template name'));
    queryClient.clear();
  });
});
