import { client } from '../client';
import type { MachineOeeRow, OeeResult, OeeReport } from '@/types/production';

export interface OeeReportParams {
  from?: string;
  to?: string;
  machine_id?: string;
}

export const oeeApi = {
  forMachine: (machineId: string, from?: string, to?: string) =>
    client.get<{ data: OeeResult & { machine_id: string } }>(`/production/oee/machine/${machineId}`, { params: { from, to } })
      .then((r) => r.data.data),
  todayAll: () =>
    client.get<{ data: MachineOeeRow[] }>('/production/oee/today').then((r) => r.data.data),
  /** Sprint P10 — full OEE report (per-machine + trend + downtime). */
  report: (params?: OeeReportParams) =>
    client.get<{ data: OeeReport }>('/production/oee/report', { params }).then((r) => r.data.data),
};
