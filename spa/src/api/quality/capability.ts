import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { RunCapabilityData, SpcCapabilityResult } from '@/types/quality/spc';

/**
 * Process capability (Cp / Cpk).
 *
 * The control-chart endpoints were removed in the 2026-08-07 scope cut — the
 * IATF inspection path already raises tolerance failures as NCRs, so the charts
 * were a parallel detector that never accumulated enough data points to plot.
 * These two calls read real inspection measurements.
 */
export const capabilityApi = {
  /** Selectable spec items + live Cpk interpretation thresholds. */
  options: () =>
    client
      .get<{
        data: {
          spec_items: Array<{ id: string; parameter_name: string; unit: string | null }>;
          capability_thresholds: { launch: number; ongoing: number; action: number; minimum_samples: number };
        };
      }>('/quality/spc/charts/options')
      .then((r) => r.data.data),

  /** Run a capability study (Cp/Cpk) with a histogram. */
  runCapability: (data: RunCapabilityData) =>
    client
      .post<ApiSuccess<SpcCapabilityResult>>('/quality/spc/capability', data)
      .then((r) => r.data.data),
};
