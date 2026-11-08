import { beforeEach, expect, it, vi } from 'vitest';
import { unwrappingClient } from '../client';
import { purchaseRequestsApi } from './purchase-requests';

vi.mock('../client', () => ({ unwrappingClient: { get: vi.fn() } }));

beforeEach(() => vi.clearAllMocks());

it('forwards TanStack Query cancellation to the authorized purchase-request detail request', async () => {
  const request = { id: 'pr-1', status: 'pending' };
  const signal = new AbortController().signal;
  vi.mocked(unwrappingClient.get).mockResolvedValue({ data: request } as never);

  await expect(purchaseRequestsApi.show('pr-1', signal)).resolves.toEqual(request);
  expect(unwrappingClient.get).toHaveBeenCalledWith('/purchasing/purchase-requests/pr-1', { signal });
});
