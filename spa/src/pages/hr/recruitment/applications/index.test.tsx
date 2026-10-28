import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AxiosHeaders, type AxiosResponse } from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import { recruitmentApi, type RecruitmentOptions } from '@/api/recruitment';
import type { PaginatedResponse } from '@/types';
import type { JobApplication } from '@/types/recruitment';
import ApplicationsListPage from './index';

vi.mock('@/api/recruitment', () => ({ recruitmentApi: { listApplications: vi.fn(), options: vi.fn() } }));

const application: JobApplication = {
  id: 'application-1', application_number: 'JA-202610-0001', tracking_code: 'RCT-XUA9JS',
  first_name: 'Jamie', last_name: 'Reyes', full_name: 'Jamie Reyes',
  email: 'jamie@example.test', phone: '09000000000', cover_letter: null,
  stage: 'screening', stage_label: 'Screening', rejected_at_stage: null, rejection_reason: null,
  applied_at: '2026-10-10T01:00:00Z', created_at: '2026-10-10T01:00:00Z', updated_at: '2026-10-10T01:00:00Z',
  job_posting: {
    id: 'posting-1', posting_number: 'JP-2026-001', title: 'Production Planner', description: '',
    requirements: '', employment_type: 'regular', salary_range_min: null, salary_range_max: null,
    show_salary: false, status: 'open', slots: 1, posted_at: null, closes_at: null,
    created_at: '', updated_at: '',
  },
};

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(recruitmentApi.listApplications).mockResolvedValue({ data: {
    data: [application],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  }, status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() } } as AxiosResponse<PaginatedResponse<JobApplication>>);
  const options: RecruitmentOptions = {
    employment_types: [], posting_statuses: [],
    application_stages: [{ value: 'screening', label: 'Screening', is_terminal: false, next: 'interview' }],
  };
  vi.mocked(recruitmentApi.options).mockResolvedValue({
    data: { data: options }, status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
  } as AxiosResponse<{ data: RecruitmentOptions }>);
});

it('anchors applicants visually and keeps the list row keyboard navigable', async () => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/recruitment/applications']}>
        <Routes>
          <Route path="/hr/recruitment/applications" element={<ApplicationsListPage />} />
          <Route path="/hr/recruitment/applications/:id" element={<h1>Application detail</h1>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  const applicant = await screen.findByRole('img', { name: 'Jamie Reyes' });
  expect(applicant).toBeVisible();
  const row = screen.getByRole('row', { name: /Jamie Reyes/ });
  expect(row).toHaveAttribute('tabindex', '0');
  fireEvent.keyDown(row, { key: 'Enter' });
  expect(await screen.findByRole('heading', { name: 'Application detail' })).toBeVisible();
});
