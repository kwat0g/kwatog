import { client } from '../client';
import type { MachineOeeRow, OeeResult } from '@/types/production';

export const oeeApi = {
  forMachine: (machineId: string, from?: string, to?: string) =>
    client.get<{ data: OeeResult & { machine_id: string } }>(`/production/oee/machine/${machineId}`, { params: { from, to } })
      .then((r) => r.data.data),
  todayAll: () =>
    client.get<{ data: MachineOeeRow[] }>('/production/oee/today').then((r) => r.data.data),
};
