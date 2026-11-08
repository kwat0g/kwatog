import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import { recruitmentApi } from '@/api/recruitment';
import type { JobPosting } from '@/types/recruitment';
import PostingDetailPage from './detail';

vi.mock('@/api/recruitment', () => ({ recruitmentApi: {
  showPosting: vi.fn(), listApplications: vi.fn(), options: vi.fn(),
  changePostingStatus: vi.fn(), deletePosting: vi.fn(), restorePosting: vi.fn(),
} }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));

const posting: JobPosting = {
  id: 'posting-1', posting_number: 'JP-2026-001', title: 'Draft Posting', description: '',
  requirements: '', employment_type: 'regular', salary_range_min: null, salary_range_max: null,
  show_salary: false, status: 'draft', slots: 1, posted_at: null, closes_at: null,
  version: '2026-10-20T01:00:00.000000+00:00', permissions: { view: true, manage: true }, available_actions: ['edit'],
  application_count: 0, created_at: '2026-10-20T01:00:00Z', updated_at: '2026-10-20T01:00:00Z',
};

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(recruitmentApi.showPosting).mockResolvedValue({
    data: { data: { ...posting, permissions: { view: true, manage: true }, available_actions: ['edit'] } },
    status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse<{ data: JobPosting }>);
  vi.mocked(recruitmentApi.listApplications).mockResolvedValue({
    data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null } },
    status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse);
  vi.mocked(recruitmentApi.options).mockResolvedValue({
    data: { data: { employment_types: [], application_stages: [], posting_statuses: [{ value: 'draft', label: 'Draft' }] } },
    status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse);
});

it('renders posting controls from the server action projection', async () => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/recruitment/postings/posting-1']}>
        <Routes><Route path="/hr/recruitment/postings/:id" element={<PostingDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByRole('button', { name: 'Edit' })).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Publish' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
});

it('shows the acting user the posting returned by the status action immediately', async () => {
  vi.mocked(recruitmentApi.showPosting).mockResolvedValueOnce({
    data: { data: { ...posting, available_actions: ['edit', 'publish', 'archive'] } },
    status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse<{ data: JobPosting }>);
  vi.mocked(recruitmentApi.changePostingStatus).mockResolvedValue({
    data: { data: { ...posting, status: 'open', available_actions: ['edit', 'close'] } },
    status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse<{ data: JobPosting }>);

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/recruitment/postings/posting-1']}>
        <Routes><Route path="/hr/recruitment/postings/:id" element={<PostingDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click(await screen.findByRole('button', { name: 'Publish' }));

  expect(await screen.findByRole('button', { name: 'Close' })).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Publish' })).not.toBeInTheDocument();
  expect(recruitmentApi.changePostingStatus).toHaveBeenCalledWith('posting-1', 'open', posting.version);
});

it('refetches and replaces stale posting actions after a state conflict', async () => {
  vi.mocked(recruitmentApi.showPosting).mockResolvedValueOnce({
    data: { data: { ...posting, available_actions: ['edit', 'publish', 'archive'] } },
    status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse<{ data: JobPosting }>);
  vi.mocked(recruitmentApi.changePostingStatus).mockRejectedValue(new AxiosError('Posting changed.', '409', undefined, undefined, {
    status: 409,
    data: { code: 'recruitment_state_conflict', message: 'This job posting changed after you opened it.' },
  } as AxiosResponse));
  vi.mocked(recruitmentApi.showPosting).mockResolvedValueOnce({
    data: { data: { ...posting, status: 'open', available_actions: ['edit', 'close'] } },
    status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse<{ data: JobPosting }>);

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/recruitment/postings/posting-1']}>
        <Routes><Route path="/hr/recruitment/postings/:id" element={<PostingDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click(await screen.findByRole('button', { name: 'Publish' }));

  expect(await screen.findByRole('button', { name: 'Close' })).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Publish' })).not.toBeInTheDocument();
  await waitFor(() => expect(recruitmentApi.showPosting).toHaveBeenCalledTimes(2));
});
