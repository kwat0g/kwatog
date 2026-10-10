import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { dashboardLayoutApi } from '@/api/dashboard-layout';
import DashboardDefaultPage from './default';

vi.mock('@/api/dashboard-layout', () => ({ dashboardLayoutApi: { layout: vi.fn(), data: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));
vi.mock('@/components/dashboard/DashboardPicker', () => ({ DashboardPicker: () => null }));
vi.mock('@/components/dashboard/registry', () => ({ LiveDashboardWidget: () => <div>Widget</div> }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('warns when refreshing widget values fails and retries without hiding the layout', async () => {
  vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({ version: 'v1', items: [{
    key: 'stock', name: 'Stock', description: null, module: 'inventory', permission: null,
    render_kind: 'scalar', link_path: null, data: null, x: 0, y: 0, w: 1, h: 1, source: 'role',
  }] });
  vi.mocked(dashboardLayoutApi.data).mockRejectedValueOnce(new Error('Unavailable')).mockResolvedValue({});
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><DashboardDefaultPage /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByRole('alert')).toHaveTextContent('Dashboard values could not be refreshed');
  expect(screen.getByText('Widget')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Retry dashboard values' }));
  await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  client.clear();
});
