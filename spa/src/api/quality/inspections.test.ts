import { beforeEach, expect, it, vi } from 'vitest';
import { client } from '../client';
import { inspectionsApi } from './inspections';

vi.mock('../client', () => ({ client: { get: vi.fn() } }));

beforeEach(() => vi.clearAllMocks());

it('forwards TanStack Query cancellation to inspection list reads', async () => {
  const result = { data: [], meta: { current_page: 1 } };
  const params = { page: 1 };
  const signal = new AbortController().signal;
  vi.mocked(client.get).mockResolvedValue({ data: result } as never);

  await expect(inspectionsApi.list(params, signal)).resolves.toEqual(result);
  expect(client.get).toHaveBeenCalledWith('/quality/inspections', { params, signal });
});

it('forwards TanStack Query cancellation to inspection option reads', async () => {
  const options = { statuses: [{ value: 'draft', label: 'Draft' }] };
  const signal = new AbortController().signal;
  vi.mocked(client.get).mockResolvedValue({ data: { data: options } } as never);

  await expect(inspectionsApi.options(signal)).resolves.toEqual(options);
  expect(client.get).toHaveBeenCalledWith('/quality/inspections/options', { signal });
});

it('forwards TanStack Query cancellation to inspection detail reads', async () => {
  const inspection = { id: 'inspection-1', status: 'draft' };
  const signal = new AbortController().signal;
  vi.mocked(client.get).mockResolvedValue({ data: { data: inspection } } as never);

  await expect(inspectionsApi.show('inspection-1', signal)).resolves.toEqual(inspection);
  expect(client.get).toHaveBeenCalledWith('/quality/inspections/inspection-1', { signal });
});

it('forwards TanStack Query cancellation to inspection chain reads', async () => {
  const chain = [{ id: 'step-1', status: 'pending' }];
  const signal = new AbortController().signal;
  vi.mocked(client.get).mockResolvedValue({ data: { data: chain } } as never);

  await expect(inspectionsApi.chain('inspection-1', signal)).resolves.toEqual(chain);
  expect(client.get).toHaveBeenCalledWith('/quality/inspections/inspection-1/chain', { signal });
});
