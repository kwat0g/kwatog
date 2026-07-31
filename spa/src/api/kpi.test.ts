import { beforeEach, describe, expect, it, vi } from 'vitest';
import { client } from '@/api/client';
import { kpiApi } from './kpi';
import type { KpiScorecardItem } from '@/types/dashboard/kpi';

const scorecardItem: KpiScorecardItem = {
  definition: {
    id: 'kpi-id',
    code: 'on_time_delivery',
    name: 'On-Time Delivery Rate',
    module: 'supply_chain',
    unit: 'percentage',
    direction: 'higher_is_better',
    target_value: '95.0000',
    warning_threshold: '90.0000',
  },
  snapshot: null,
};

describe('kpiApi', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('normalizes a legacy sparse scorecard object into a list', async () => {
    vi.spyOn(client, 'get').mockResolvedValue({
      data: { data: { 3: scorecardItem } },
    } as never);

    await expect(kpiApi.scorecard(2026, 6)).resolves.toEqual([scorecardItem]);
  });

  it('keeps a normal scorecard list unchanged', async () => {
    vi.spyOn(client, 'get').mockResolvedValue({
      data: { data: [scorecardItem] },
    } as never);

    await expect(kpiApi.scorecard(2026, 6)).resolves.toEqual([scorecardItem]);
  });
});
