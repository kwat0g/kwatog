import { client } from '../client';
import type { PaginatedResponse } from '@/types';
import type { PriceOffer, PriceRequest, ItemCostReview } from '@/types/pricing';

export const pricingRequestsApi = {
  list: (page = 1) =>
    client
      .get<PaginatedResponse<PriceRequest>>('/crm/price-requests', { params: { page } })
      .then((r) => r.data),
  show: (id: string) =>
    client.get<{ data: PriceRequest }>(`/crm/price-requests/${id}`).then((r) => r.data.data),
  create: (data: PriceOffer & { product_id: string; customer_id: string }) =>
    client.post('/crm/price-requests', data).then((r) => r.data),
  offer: (id: string, data: PriceOffer) =>
    client.put(`/crm/price-requests/${id}/offer`, data).then((r) => r.data),
  approve: (id: string, notes: string, version: number, cost_signature: string) =>
    client.post(`/crm/price-requests/${id}/approve`, { notes, version, cost_signature }),
  withdraw: (id: string, notes: string) =>
    client.post(`/crm/price-requests/${id}/withdraw`, { notes }),
  costs: (page = 1) =>
    client
      .get<PaginatedResponse<ItemCostReview>>('/inventory/cost-reviews', { params: { page } })
      .then((r) => r.data),
  decideCost: (id: string, decision: 'approve' | 'reject', notes: string, version: number) =>
    client.post(`/inventory/cost-reviews/${id}/decide`, { decision, notes, version }),
  renewCost: (id: string, notes: string) =>
    client.post(`/inventory/cost-reviews/${id}/renew`, { notes }),
};
