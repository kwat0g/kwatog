import { client } from '../client';
import type { ApiSuccess, ListParams, PaginatedResponse } from '@/types';
import type { CalibrationRecord, CalibrationRecordData, CalibrationStatus } from '@/types/quality';

export interface CalibrationListParams extends ListParams {
 status?: CalibrationStatus;
}

export const calibrationApi = {
 list: (params?: CalibrationListParams) =>
  client.get<PaginatedResponse<CalibrationRecord>>('/quality/calibration', { params }).then((r) => r.data),
 show: (id: string) =>
  client.get<ApiSuccess<CalibrationRecord>>(`/quality/calibration/${id}`).then((r) => r.data.data),
 create: (data: CalibrationRecordData) =>
  client.post<ApiSuccess<CalibrationRecord>>('/quality/calibration', data).then((r) => r.data.data),
 update: (id: string, data: CalibrationRecordData) =>
  client.patch<ApiSuccess<CalibrationRecord>>(`/quality/calibration/${id}`, data).then((r) => r.data.data),
 record: (id: string, date: string) =>
  client.post<ApiSuccess<CalibrationRecord>>(`/quality/calibration/${id}/record`, { date }).then((r) => r.data.data),
};
