import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { ncrsApi } from '@/api/quality/ncrs';
import NcrEffectivenessPage from './effectiveness';

vi.mock('@/api/quality/ncrs', () => ({ ncrsApi: { due: vi.fn() } }));

afterEach(() => vi.clearAllMocks());

it('loads the current user effectiveness queue from its sidebar deep link', async () => {
  vi.mocked(ncrsApi.due).mockResolvedValue({
    data: [],
    meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 },
  } as never);

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/quality/ncrs/effectiveness?signal=awaiting_my_action']}>
        <Routes>
          <Route path="/quality/ncrs/effectiveness" element={<NcrEffectivenessPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  await screen.findByText(/0 due checks/);
  expect(ncrsApi.due).toHaveBeenCalledWith({ page: 1, per_page: 25, signal: 'awaiting_my_action' });
});
