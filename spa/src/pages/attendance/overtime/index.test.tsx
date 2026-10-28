import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { overtimeApi } from '@/api/attendance/overtime';
import OvertimeListPage from './index';

vi.mock('@/api/attendance/overtime', () => ({
  overtimeApi: {
    list: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }),
    options: vi.fn().mockResolvedValue({ statuses: [{ value: 'pending', label: 'Pending' }] }),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false, canApprove: () => true }) }));

it('loads and retains the actionable overtime filter from its deep link', async () => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/attendance/overtime?signal=awaiting_my_approval']}>
        <OvertimeListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('No OT requests match')).toBeVisible();
  expect(overtimeApi.list).toHaveBeenCalledWith(expect.objectContaining({
    signal: 'awaiting_my_approval',
  }));
  expect(screen.queryByText('Status')).not.toBeInTheDocument();
});
