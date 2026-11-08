import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { LeaveTypesManager } from './types';
import { leaveTypesApi } from '@/api/leave';
import type { LeaveType } from '@/types/leave';

vi.mock('@/api/leave', () => ({
 leaveTypesApi: {
  list: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
  restore: vi.fn(),
 },
}));

vi.mock('@/hooks/usePermission', () => ({
 usePermission: () => ({ can: () => true }),
}));


const activeType: LeaveType = {
 id: 'lt-vl',
 name: 'Vacation Leave',
 code: 'VL',
 default_balance: '15.0',
 max_carryover_days: null,
 is_paid: true,
 requires_document: false,
 is_convertible_on_separation: false,
 is_convertible_year_end: false,
 conversion_rate: '1.0',
 is_active: true,
 created_at: '2026-01-01T00:00:00Z',
 updated_at: '2026-01-01T00:00:00Z',
 deleted_at: null,
};

const archivedType: LeaveType = {
 ...activeType,
 id: 'lt-bl',
 name: 'Bereavement Leave',
 code: 'BL',
 deleted_at: '2026-08-01T00:00:00Z',
};

function page(data: LeaveType[]) {
 return {
  data,
  meta: {
   current_page: 1,
   last_page: 1,
   per_page: 50,
   total: data.length,
   from: data.length > 0 ? 1 : null,
   to: data.length > 0 ? data.length : null,
  },
  links: { first: '', last: '', prev: null, next: null },
 };
}

function renderManager() {
 const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
 });

 render(
  <QueryClientProvider client={queryClient}>
   <LeaveTypesManager />
  </QueryClientProvider>,
 );
}

afterEach(() => cleanup());

describe('leave type archive actions', () => {
 beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(leaveTypesApi.list).mockImplementation(async (params) => {
   if (params?.trashed === 'only') return page([archivedType]);
   if (params?.trashed === 'with') return page([activeType, archivedType]);
   return page([activeType]);
  });
 });

 it('keeps Edit for active rows and exposes Restore instead of Edit for archived rows', async () => {
  renderManager();

  expect(await screen.findAllByRole('button', { name: 'Edit Vacation Leave' })).not.toHaveLength(0);
  expect(screen.getAllByRole('button', { name: 'Archive Vacation Leave' })).not.toHaveLength(0);

  fireEvent.click(screen.getAllByRole('radio', { name: 'Show active and archived records' })[0]!);

  expect(await screen.findAllByRole('button', { name: 'Restore Bereavement Leave' })).not.toHaveLength(0);
  expect(screen.queryAllByRole('button', { name: 'Edit Bereavement Leave' })).toHaveLength(0);
  expect(screen.getAllByRole('button', { name: 'Edit Vacation Leave' })).not.toHaveLength(0);
  expect(screen.getAllByRole('button', { name: 'Archive Vacation Leave' })).not.toHaveLength(0);

  fireEvent.click(screen.getAllByRole('radio', { name: 'Show archived records only' })[0]!);

  expect(await screen.findAllByRole('button', { name: 'Restore Bereavement Leave' })).not.toHaveLength(0);
  expect(screen.queryAllByRole('button', { name: 'Edit Bereavement Leave' })).toHaveLength(0);
  expect(leaveTypesApi.update).not.toHaveBeenCalled();
 });

 it('does not show active rows as archived while archived results are loading', async () => {
  let releaseArchived!: (data: ReturnType<typeof page>) => void;
  vi.mocked(leaveTypesApi.list).mockImplementation((params) => {
   if (params?.trashed === 'only') {
    return new Promise((resolve) => { releaseArchived = resolve; });
   }
   return Promise.resolve(page([activeType]));
  });
  renderManager();

  expect(await screen.findAllByRole('button', { name: 'Archive Vacation Leave' })).not.toHaveLength(0);
  fireEvent.click(screen.getAllByRole('radio', { name: 'Show archived records only' })[0]!);
  await waitFor(() => expect(leaveTypesApi.list).toHaveBeenLastCalledWith(
   expect.objectContaining({ trashed: 'only' }),
  ));

  expect(screen.queryAllByRole('button', { name: 'Restore Vacation Leave' })).toHaveLength(0);

  releaseArchived(page([archivedType]));
  expect(await screen.findAllByRole('button', { name: 'Restore Bereavement Leave' })).not.toHaveLength(0);
 });
});
