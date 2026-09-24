import { beforeEach, describe, expect, it, vi } from 'vitest';
import { client } from '../client';
import { grnApi } from './grn';

vi.mock('../client', () => ({
  client: {
    post: vi.fn(),
  },
}));

describe('grnApi.retryGl', () => {
  beforeEach(() => vi.clearAllMocks());

  it('calls the accepted-GRN GL retry route and unwraps the resource', async () => {
    const grn = { id: 'grn-1' };
    vi.mocked(client.post).mockResolvedValue({ data: { data: grn } });

    await expect(grnApi.retryGl('grn-1')).resolves.toEqual(grn);
    expect(client.post).toHaveBeenCalledWith('/inventory/grn/grn-1/retry-gl');
  });
});
