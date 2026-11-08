import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { journalEntriesApi } from '@/api/accounting/journal-entries';
import type { JournalEntry } from '@/types/accounting';
import JournalEntryDetailPage from './detail';

vi.mock('@/api/accounting/journal-entries', () => ({ journalEntriesApi: {
  show: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
  restore: vi.fn(),
  post: vi.fn(),
  reverse: vi.fn(),
  pdfUrl: vi.fn(() => '/api/v1/journal-entries/je-1/pdf'),
} }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));

function entry(availableActions: NonNullable<JournalEntry['available_actions']>): JournalEntry {
  return {
    id: 'je-1',
    entry_number: 'JE-202604-0001',
    date: '2026-04-15',
    description: 'Manual journal entry',
    reference_type: null,
    reference_id: null,
    reference_label: null,
    total_debit: '100.00',
    total_credit: '100.00',
    status: 'draft',
    reversed_by_entry_id: null,
    posted_at: null,
    lines: [],
    available_actions: availableActions,
  };
}

function renderPage(value: JournalEntry) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(journalEntriesApi.show).mockResolvedValue(value);

  return {
    ...render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/accounting/journal-entries/je-1']}>
        <Routes>
          <Route path="/accounting/journal-entries/:id" element={<JournalEntryDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
    ),
    client,
  };
}

describe('journal entry detail server actions', () => {
  beforeEach(() => vi.clearAllMocks());

  it('hides draft controls when the API denies them even if the local status is draft', async () => {
    renderPage(entry({
      can_edit: false,
      can_delete: false,
      can_restore: false,
      can_post: false,
      can_reverse: false,
    }));

    expect(await screen.findByText('JE-202604-0001')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Post' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
  });

  it('shows only draft controls allowed by the API response', async () => {
    renderPage(entry({
      can_edit: true,
      can_delete: true,
      can_restore: false,
      can_post: true,
      can_reverse: false,
    }));

    expect(await screen.findByRole('button', { name: 'Edit' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Post' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Restore' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reverse' })).not.toBeInTheDocument();
  });

  it('refreshes the active journal list and detail after a stale post conflict', async () => {
    const draft = entry({
      can_edit: true,
      can_delete: true,
      can_restore: false,
      can_post: true,
      can_reverse: false,
    });
    const posted = {
      ...entry({
        can_edit: false,
        can_delete: false,
        can_restore: false,
        can_post: false,
        can_reverse: false,
      }),
      status: 'posted',
      status_label: 'Posted',
    } as JournalEntry;
    vi.mocked(journalEntriesApi.show).mockResolvedValueOnce(draft).mockResolvedValueOnce(posted);
    vi.mocked(journalEntriesApi.post).mockRejectedValue({
      response: {
        status: 409,
        data: { code: 'journal_entry_state_conflict', message: 'This entry has already been posted.' },
      },
    });
    const { client } = renderPage(draft);
    const listKey = ['accounting', 'journal-entries', 'list', { status: 'draft' }];
    client.setQueryData(listKey, [{ id: 'je-1', status: 'draft' }]);

    fireEvent.click(await screen.findByRole('button', { name: 'Post' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Post' }));

    expect(await screen.findByText('Posted')).toBeInTheDocument();
    await waitFor(() => expect(client.getQueryState(listKey)?.isInvalidated).toBe(true));
    expect(journalEntriesApi.show).toHaveBeenCalledTimes(2);
  });
});
