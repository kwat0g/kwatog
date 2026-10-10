import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { alertsApi } from '@/api/alerts';
import type { Alert } from '@/types/alerts';
import AlertsListPage from './index';

vi.mock('@/api/alerts', () => ({
  alertsApi: {
    options: vi.fn(),
    list: vi.fn(),
    dismiss: vi.fn(),
    markRead: vi.fn(),
  },
}));

vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: () => true }),
}));

const alert = (overrides: Partial<Alert> = {}): Alert => ({
  id: 'alert-1',
  type: 'scheduler_stale',
  severity: 'critical',
  title: 'Scheduler is stale',
  message: 'A scheduled task has not run.',
  entity_type: null,
  entity: null,
  metadata: {},
  is_read: false,
  is_dismissed: false,
  dismissed_at: null,
  resolved_at: null,
  email_status: 'failed',
  email_attempts: 2,
  email_failed_at: '2026-10-02T12:00:00.000Z',
  created_at: '2026-10-02T12:00:00.000Z',
  ...overrides,
});

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AlertsListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('critical alert email state', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(alertsApi.options).mockResolvedValue({ types: [], severities: [] });
    vi.mocked(alertsApi.list).mockResolvedValue({
      data: [alert()],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
    });
  });

  it('shows a failed delivery and scheduled retry on the alert', async () => {
    renderPage();

    const status = await screen.findByRole('status');

    expect(status).toHaveTextContent('Critical alert email failed');
    expect(status).toHaveTextContent('Retry scheduled');
    expect(status).toHaveTextContent('2 attempts');
  });

  it('shows when critical email retries have stopped', async () => {
    vi.mocked(alertsApi.list).mockResolvedValue({
      data: [alert({ email_status: 'terminal_failed', email_attempts: 5 })],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
    });
    renderPage();

    const status = await screen.findByRole('status');

    expect(status).toHaveTextContent('Critical alert email stopped after 5 attempts');
    expect(status).toHaveTextContent('Contact an administrator');
  });
});
