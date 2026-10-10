import { expect, it, vi } from 'vitest';
import { client } from '../client';
import { workOrdersApi } from './workOrders';

vi.mock('../client', () => ({ client: { get: vi.fn() } }));
it.each([false, true])(
  'serializes holiday authorization as a Laravel-compatible query value: %s',
  async (holiday) => {
    vi.mocked(client.get).mockResolvedValue({ data: { data: [] } });
    await workOrdersApi.crewOptions('wo', '', undefined, {
      scheduled_start: '2026-10-07T02:00:00+08:00',
      scheduled_end: '2026-10-07T04:00:00+08:00',
      holiday_authorized: holiday,
    });
    expect(client.get).toHaveBeenLastCalledWith('/production/work-orders/wo/crew-options', {
      params: expect.objectContaining({ holiday_authorized: holiday ? 1 : 0 }),
    });
  },
);

it('treats a missing receipt as unconfirmed, while preserving authorization errors', async () => {
  const { AxiosError } = await import('axios');
  vi.mocked(client.get).mockRejectedValueOnce(
    new AxiosError('Not found', undefined, undefined, undefined, { status: 404 } as never),
  );
  await expect(workOrdersApi.myOutputReceipt('wo', 'same-key')).resolves.toBeNull();
  vi.mocked(client.get).mockRejectedValueOnce(
    new AxiosError('Forbidden', undefined, undefined, undefined, { status: 403 } as never),
  );
  await expect(workOrdersApi.myOutputReceipt('wo', 'same-key')).rejects.toMatchObject({
    response: { status: 403 },
  });
});
