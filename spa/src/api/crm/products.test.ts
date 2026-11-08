import { beforeEach, expect, it, vi } from 'vitest';
import { client } from '../client';
import { productsApi } from './products';

vi.mock('../client', () => ({ client: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

const resource = { id: 'product-1', part_number: 'P-001', name: 'Molded Part' };

beforeEach(() => vi.resetAllMocks());

it('unwraps the existing ProductResource from a financial proposal response', async () => {
  vi.mocked(client.post).mockResolvedValue({ data: { data: resource } } as never);

  await expect(productsApi.proposeFinancial('product-1', { standard_cost: '12.00', notes: 'Updated machine rate.' }))
    .resolves.toEqual(resource);
  expect(client.post).toHaveBeenCalledWith('/crm/products/product-1/financial-proposal', {
    standard_cost: '12.00', notes: 'Updated machine rate.',
  });
});

it('unwraps the existing ProductResource from a financial decision response', async () => {
  vi.mocked(client.post).mockResolvedValue({ data: { data: resource } } as never);

  await expect(productsApi.decideFinancial('product-1', 'proposal-1', 'approve', 'Reviewed costs.'))
    .resolves.toEqual(resource);
  expect(client.post).toHaveBeenCalledWith('/crm/products/product-1/financial-decision', {
    proposal_id: 'proposal-1', decision: 'approve', notes: 'Reviewed costs.',
  });
});
