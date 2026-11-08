import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { get, can } = vi.hoisted(() => ({ get: vi.fn(), can: vi.fn(() => true) }));

vi.mock('@/api/actionCenter', () => ({ actionCenterApi: { get } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can }) }));

import { DashboardAttentionList } from './DashboardShell';

describe('DashboardAttentionList freshness', () => {
  beforeEach(() => vi.clearAllMocks());

  it('does not poll while shared realtime invalidation owns refreshes', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    get.mockResolvedValue({ items: [], summary: { total: 0 } });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><DashboardAttentionList /></MemoryRouter>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(queryClient.getQueryData(['action-center'])).toBeDefined());

    const query = queryClient.getQueryCache().find({ queryKey: ['action-center'] });
    expect((query?.options as { refetchInterval?: unknown }).refetchInterval).toBeUndefined();
    queryClient.clear();
  });
});
