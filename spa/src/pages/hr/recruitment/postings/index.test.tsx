import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AxiosHeaders, type AxiosResponse } from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import { recruitmentApi, type RecruitmentOptions } from '@/api/recruitment';
import type { PaginatedResponse } from '@/types';
import type { JobPosting } from '@/types/recruitment';
import PostingsListPage from './index';

vi.mock('@/api/recruitment', () => ({ recruitmentApi: { listPostings: vi.fn(), options: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

const posting: JobPosting = {
  id: 'posting-1', posting_number: 'JP-2026-001', title: 'Production Planner', description: '',
  requirements: '', employment_type: 'regular', salary_range_min: null, salary_range_max: null,
  show_salary: false, status: 'open', status_label: 'Open', slots: 2, posted_at: '2026-10-20T01:00:00Z',
  closes_at: null, department: { id: 'department-1', name: 'Production' }, application_count: 8,
  created_at: '2026-10-20T01:00:00Z', updated_at: '2026-10-20T01:00:00Z',
};

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(recruitmentApi.listPostings).mockResolvedValue({ data: {
    data: [posting],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  }, status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() } } as AxiosResponse<PaginatedResponse<JobPosting>>);
  const options: RecruitmentOptions = {
    employment_types: [], application_stages: [],
    posting_statuses: [{ value: 'open', label: 'Open' }],
  };
  vi.mocked(recruitmentApi.options).mockResolvedValue({
    data: { data: options }, status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse<{ data: RecruitmentOptions }>);
});

it('emphasizes the posting title and uses a category tag for its department', async () => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/recruitment/postings']}>
        <Routes>
          <Route path="/hr/recruitment/postings" element={<PostingsListPage />} />
          <Route path="/hr/recruitment/postings/:id" element={<h1>Posting detail</h1>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('Production Planner')).toBeVisible();
  expect(screen.getByText('Production')).toBeVisible();
  expect(screen.getByText('8')).toBeVisible();
});
