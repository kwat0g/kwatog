import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { approvalsApi } from '@/api/approvals';
import ApprovalsBoardPage from './index';

vi.mock('@/api/approvals', () => ({
  approvalsApi: {
    board: vi.fn(),
    options: vi.fn(),
  },
}));

const board = {
  my_action: [],
  awaiting_others: [],
  approved: [],
  rejected: [],
  summary: { my_action: 0, awaiting_others: 0, approved: 0, rejected: 0 },
  meta: {
    pending_limit: 100,
    history_limit: 50,
    pending_truncated: false,
    history_truncated: false,
  },
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ApprovalsBoardPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('approvals board', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(approvalsApi.board).mockResolvedValue(board);
    vi.mocked(approvalsApi.options).mockResolvedValue({ kinds: [], overdue_hours: 24 });
  });

  it('lets reviewers refresh the queue immediately', async () => {
    renderPage();

    await screen.findByText('Nothing waiting on you. Nice.');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh approvals' }));

    await waitFor(() => expect(approvalsApi.board).toHaveBeenCalledTimes(2));
  });
});
