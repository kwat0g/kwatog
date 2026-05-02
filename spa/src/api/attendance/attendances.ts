import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Attendance } from '@/types/attendance';

export interface AttendanceListParams extends ListParams {
  employee_id?: string;
  department_id?: string;
  status?: string;
  from?: string;
  to?: string;
}

export interface CreateAttendanceData {
  employee_id: string;
  date: string;
  shift_id?: string;
  time_in?: string;
  time_out?: string;
  is_rest_day?: boolean;
  remarks?: string;
}

export type UpdateAttendanceData = Partial<Omit<CreateAttendanceData, 'employee_id' | 'date'>>;

export interface ImportResult {
  total: number;
  imported: number;
  skipped: number;
  errors: { row: number; message: string }[];
}

export const attendancesApi = {
  list: (params?: AttendanceListParams) =>
    client.get<PaginatedResponse<Attendance>>('/attendance/attendances', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Attendance>>(`/attendance/attendances/${id}`).then((r) => r.data.data),
  create: (data: CreateAttendanceData) =>
    client.post<ApiSuccess<Attendance>>('/attendance/attendances', data).then((r) => r.data.data),
  update: (id: string, data: UpdateAttendanceData) =>
    client.put<ApiSuccess<Attendance>>(`/attendance/attendances/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/attendance/attendances/${id}`),
  import: (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    return client.post<{ data: ImportResult }>('/attendance/attendances/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then((r) => r.data.data);
  },
};
