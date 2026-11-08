import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';
import type { WoOperation } from '@/types/production/routing';
import {
  reconcileWorkOrderOperation,
  workOrderOperationsQueryKey,
} from './productionOperations';

const operation = (id: string, status: WoOperation['status']): WoOperation => ({
  id,
  status,
  operation_name: id,
  sequence: 1,
  machine: null,
  mold: null,
  operator: null,
  planned_start: null,
  planned_end: null,
  actual_start: null,
  actual_end: null,
  setup_start: null,
  setup_end: null,
  qty_planned: '10.0000',
  qty_completed: '0.0000',
  qty_scrapped: '0.0000',
  scrap_reason: null,
  downtime_minutes: '0.00',
  notes: null,
});

describe('work-order operation response reconciliation', () => {
  it('updates the acting user cache from the API response and preserves unrelated fields', () => {
    const queryClient = new QueryClient();
    const initial = operation('op-1', 'in_progress');
    initial.logs = [{
      id: 'log-1',
      event_type: 'start_production',
      event_label: 'Production started',
      recorded_at: '2026-10-30T00:00:00Z',
    }];
    queryClient.setQueryData(workOrderOperationsQueryKey('wo-1'), [initial, operation('op-2', 'pending')]);

    reconcileWorkOrderOperation(queryClient, 'wo-1', {
      ...operation('op-1', 'paused'),
      qty_completed: '3.0000',
    });

    const rows = queryClient.getQueryData<WoOperation[]>(workOrderOperationsQueryKey('wo-1'));
    expect(rows?.[0]).toMatchObject({ id: 'op-1', status: 'paused', qty_completed: '3.0000' });
    expect(rows?.[0].logs).toEqual(initial.logs);
    expect(rows?.[1]).toMatchObject({ id: 'op-2', status: 'pending' });
  });

  it('does not create a partial list when the operations query is not cached', () => {
    const queryClient = new QueryClient();

    reconcileWorkOrderOperation(queryClient, 'wo-1', operation('op-1', 'paused'));

    expect(queryClient.getQueryData(workOrderOperationsQueryKey('wo-1'))).toBeUndefined();
  });
});
