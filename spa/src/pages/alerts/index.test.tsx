import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { alertsApi } from '@/api/alerts';
import type { Alert } from '@/types/alerts';
import AlertsListPage from './index';

vi.mock('@/api/alerts', () => ({
  alertsApi: {
    options: vi.fn(),
    list: vi.fn(),
    unreadCount: vi.fn(),
    dismiss: vi.fn(),
    markRead: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

const alert: Alert = {
  id: 'alert-1',
  type: 'stock_low',
  type_label: 'Low stock',
  severity: 'warning',
  title: 'Resin stock is low',
  message: 'Only 10 kg remain.',
  entity_type: 'Item',
  entity: null,
  metadata: {},
  is_read: false,
  is_dismissed: false,
  dismissed_at: null,
  resolved_at: null,
  email_status: null,
  email_attempts: 0,
  email_failed_at: null,
  created_at: '2026-11-02T00:00:00Z',
};

const page = (rows: Alert[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, per_page: 50, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null },
});

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter><AlertsListPage /></MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('alert mutation response reconciliation', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(alertsApi.options).mockResolvedValue({ types: [], severities: [] });
    vi.mocked(alertsApi.unreadCount).mockResolvedValue({ count: 1 });
  });

  it('removes a dismissed alert immediately while the refreshed list request is pending', async () => {
    vi.mocked(alertsApi.list)
      .mockResolvedValueOnce(page([alert]))
      .mockReturnValue(new Promise(() => {}));
    vi.mocked(alertsApi.unreadCount)
      .mockResolvedValueOnce({ count: 1 })
      .mockReturnValue(new Promise(() => {}));
    vi.mocked(alertsApi.dismiss).mockResolvedValue({
      ...alert,
      is_dismissed: true,
      dismissed_at: '2026-11-02T00:01:00Z',
    });
    renderPage();

    const dismiss = await screen.findByRole('button', { name: 'Dismiss alert Resin stock is low' });
    fireEvent.click(dismiss);
    await waitFor(() => expect(alertsApi.dismiss).toHaveBeenCalledWith('alert-1'));

    expect(await screen.findByText('No active alerts')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Show shared unread alerts (0)' })).toBeInTheDocument();
  });

  it('removes the unread action immediately after the server marks the alert read', async () => {
    vi.mocked(alertsApi.list)
      .mockResolvedValueOnce(page([alert]))
      .mockReturnValue(new Promise(() => {}));
    vi.mocked(alertsApi.unreadCount)
      .mockResolvedValueOnce({ count: 1 })
      .mockReturnValue(new Promise(() => {}));
    vi.mocked(alertsApi.markRead).mockResolvedValue({ ...alert, is_read: true });
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Mark alert as read: Resin stock is low' }));
    await waitFor(() => expect(alertsApi.markRead).toHaveBeenCalledWith('alert-1'));

    expect(screen.queryByRole('button', { name: 'Mark alert as read: Resin stock is low' })).not.toBeInTheDocument();
    expect(screen.queryByText('new')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Show shared unread alerts (0)' })).toBeInTheDocument();
  });
});
