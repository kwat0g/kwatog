import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';
import type { PaginatedResponse } from '@/types';
import type { StockMovement } from '@/types/inventory';
import { applyStockMovementListResponse, canRetryStockMovement } from './stockMovementCache';

const page = (movement: StockMovement): PaginatedResponse<StockMovement> => ({
  data: [movement],
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
  links: { first: '/movements?page=1', last: '/movements?page=1', prev: null, next: null },
});

const movement: StockMovement = {
  id: 'movement-1',
  created_at: '2026-11-02T00:00:00Z',
  movement_type: 'adjustment_in',
  item: null,
  from_location: null,
  to_location: null,
  quantity: '2.000',
  unit_cost: '5.0000',
  total_cost: '10.00',
  lot_number: null,
  expiry_date: null,
  gl_handoff: {
    status: 'manual_required',
    status_label: 'Manual Required',
    message: 'Accounting setup required',
    at: '2026-11-02T00:00:00Z',
  },
  reference_type: 'opening',
  reference_id: null,
  remarks: null,
  creator: null,
  version: 2,
  permissions: { retry_gl: true },
  available_actions: ['retry_gl'],
};

describe('stock movement response cache', () => {
  it('uses the server action projection and applies the actor response to active list pages', () => {
    const queryClient = new QueryClient();
    const firstPageKey = ['inventory', 'movements', { page: 1, per_page: 50 }] as const;
    const secondPageKey = ['inventory', 'movements', { page: 2, per_page: 50 }] as const;
    queryClient.setQueryData(firstPageKey, page(movement));
    queryClient.setQueryData(secondPageKey, page(movement));
    queryClient.setQueryData(['inventory', 'movements', 'options'], { movement_types: [] });

    const updated: StockMovement = {
      ...movement,
      version: 3,
      gl_handoff: { ...movement.gl_handoff, status: 'generated' },
      available_actions: [],
    };

    expect(canRetryStockMovement(movement)).toBe(true);
    expect(canRetryStockMovement(updated)).toBe(false);
    applyStockMovementListResponse(queryClient, updated);

    expect(queryClient.getQueryData(firstPageKey)).toEqual(page(updated));
    expect(queryClient.getQueryData(secondPageKey)).toEqual(page(updated));
    expect(queryClient.getQueryData(['inventory', 'movements', 'options'])).toEqual({ movement_types: [] });
    queryClient.clear();
  });
});
