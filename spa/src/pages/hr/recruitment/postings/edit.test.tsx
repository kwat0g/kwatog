import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import { recruitmentApi } from '@/api/recruitment';
import { departmentsApi } from '@/api/hr/departments';
import { positionsApi } from '@/api/hr/positions';
import type { PaginatedResponse } from '@/types';
import type { Department, Position } from '@/types/hr';
import type { JobPosting } from '@/types/recruitment';
import PostingEditPage from './edit';

vi.mock('@/api/recruitment', () => ({ recruitmentApi: {
  showPosting: vi.fn(), options: vi.fn(), updatePosting: vi.fn(),
} }));
vi.mock('@/api/hr/departments', () => ({ departmentsApi: { list: vi.fn() } }));
vi.mock('@/api/hr/positions', () => ({ positionsApi: { list: vi.fn() } }));

const posting: JobPosting = {
  id: 'posting-1', posting_number: 'JP-2026-001', title: 'Original Title', description: 'Description',
  requirements: 'At least one year of experience', employment_type: 'regular', salary_range_min: null,
  salary_range_max: null, show_salary: false, status: 'draft', slots: 1, posted_at: null, closes_at: null,
  position: { id: 'position-1', title: 'Molding Operator' }, department: { id: 'department-1', name: 'Production' },
  application_count: 0, version: '2026-10-20T01:00:00.000000+00:00',
  permissions: { view: true, manage: true }, available_actions: ['edit', 'publish', 'archive'],
  created_at: '2026-10-20T01:00:00Z', updated_at: '2026-10-20T01:00:00Z',
};

const response = <T,>(data: T) => ({
  data, status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
}) as AxiosResponse<T>;

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(recruitmentApi.showPosting).mockResolvedValue(response({ data: posting }));
  vi.mocked(recruitmentApi.options).mockResolvedValue(response({ data: {
    employment_types: [{ value: 'regular', label: 'Regular' }], application_stages: [], posting_statuses: [],
  } }));
  vi.mocked(departmentsApi.list).mockResolvedValue({
    data: [
      { id: 'department-1', name: 'Production', code: 'PROD', is_active: true },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  } as PaginatedResponse<Department>);
  vi.mocked(positionsApi.list).mockResolvedValue({
    data: [
      { id: 'position-1', title: 'Molding Operator', department_id: 'department-1' },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  } as PaginatedResponse<Position>);
});

it('refreshes after a stale save while preserving the typed form values', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/hr/recruitment/postings/posting-1/edit']}>
        <Routes><Route path="/hr/recruitment/postings/:id/edit" element={<PostingEditPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  const title = await screen.findByLabelText(/Job Title/);
  fireEvent.change(title, { target: { value: 'My unsaved title' } });
  vi.mocked(recruitmentApi.showPosting).mockResolvedValueOnce(response({
    data: { ...posting, title: 'Updated by another user', version: '2026-10-20T02:00:00.000000+00:00' },
  }));
  vi.mocked(recruitmentApi.updatePosting).mockRejectedValueOnce(new AxiosError('Posting changed.', '409', undefined, undefined, {
    status: 409,
    data: { code: 'recruitment_state_conflict', message: 'This job posting changed after you opened it.' },
  } as AxiosResponse));

  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));

  expect(await screen.findByText(/Your typed edits are still here/)).toBeVisible();
  expect(title).toHaveValue('My unsaved title');
  expect(await screen.findByRole('button', { name: 'Review latest posting' })).toBeVisible();
  await waitFor(() => expect(recruitmentApi.showPosting).toHaveBeenCalledTimes(2));
  expect(recruitmentApi.updatePosting).toHaveBeenCalledWith('posting-1', expect.objectContaining({
    title: 'My unsaved title',
  }), posting.version);
});
