import { client } from '../client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import type {
  MachineConditionReading,
  RecordConditionReadingData,
  ConditionReadingResult,
  MachineHealthSnapshot,
  ConditionTrendPoint,
} from '@/types/maintenance';

export const conditionReadingsApi = {
  options: () => client.get<{ data: { metrics: Array<{ value: string; label: string; unit: string }>; sources: Array<{ value: string; label: string }>; default_source: string } }>('/maintenance/condition-readings/options').then(r => r.data.data),
  list: (params?: { machine_id: string; metric?: string; page?: number; per_page?: number }) =>
    client.get<PaginatedResponse<MachineConditionReading>>('/maintenance/condition-readings', { params }).then(r => r.data),

  record: (data: RecordConditionReadingData) =>
    client.post<ApiSuccess<ConditionReadingResult>>('/maintenance/condition-readings', data).then(r => r.data.data),

  trend: (params: { machine_id: string; metric: string; limit?: number }) =>
    client.get<ApiSuccess<ConditionTrendPoint[]>>('/maintenance/condition-readings/trend', { params }).then(r => r.data.data),

  healthSnapshot: (params: { machine_id: string }) =>
    client.get<ApiSuccess<MachineHealthSnapshot[]>>('/maintenance/condition-readings/health-snapshot', { params }).then(r => r.data.data),
};
