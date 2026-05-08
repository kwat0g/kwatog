import { client } from '../client';
import type { StockCardParams, StockCardResponse } from '@/types/stockCard';

/**
 * Series F — Task F3. Stock card API.
 */
export const stockCardApi = {
  show: (itemId: string, params?: StockCardParams) =>
    client
      .get<StockCardResponse>(`/inventory/items/${itemId}/stock-card`, {
        params: {
          from: params?.from,
          to: params?.to,
          location_id: params?.location_id,
        },
      })
      .then((r) => r.data.data),
};
