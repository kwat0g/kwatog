import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Employee } from '@/types/hr';

export interface EmployeeListParams extends ListParams {
  department_id?: string;
  position_id?: string;
  status?: string;
  employment_type?: string;
  pay_type?: string;
}

export interface CreateEmployeeData {
  first_name: string;
  middle_name?: string;
  last_name: string;
  suffix?: string;
  birth_date: string;
  gender: string;
  civil_status: string;
  nationality?: string;
  street_address?: string;
  barangay?: string;
  city?: string;
  province?: string;
  zip_code?: string;
  mobile_number?: string;
  email?: string;
  emergency_contact_name?: string;
  emergency_contact_relation?: string;
  emergency_contact_phone?: string;
  sss_no?: string;
  philhealth_no?: string;
  pagibig_no?: string;
  tin?: string;
  department_id: string;
  position_id: string;
  employment_type: string;
  pay_type: string;
  date_hired: string;
  date_regularized?: string;
  basic_monthly_salary?: string;
  semi_monthly_rate?: string;
  bank_name?: string;
  bank_account_no?: string;
  shift_id?: string;
}

export type UpdateEmployeeData = Partial<CreateEmployeeData> & { status?: string };

export interface SeparateData {
  separation_reason: 'resigned' | 'terminated' | 'retired' | 'end_of_contract';
  separation_date: string;
  remarks?: string;
}

export interface EmployeeOptions {
  statuses: Array<{ value: string; label: string }>;
  employment_types: Array<{ value: string; label: string }>;
  pay_types: Array<{ value: string; label: string }>;
  genders: Array<{ value: string; label: string }>;
  civil_statuses: Array<{ value: string; label: string }>;
  separation_reasons: Array<{ value: string; label: string }>;
  skill_levels: Array<{ value: string; label: string }>;
}

/**
 * Headcount per status across the whole filtered set — not the current page.
 * `counts` is keyed by EmployeeStatus and zero-filled server-side, so a missing
 * status is impossible rather than ambiguous.
 */
export interface EmployeeStatusCounts {
  counts: Record<string, number>;
  total: number;
}

export const employeesApi = {
  options: () =>
    client.get<{ data: EmployeeOptions }>('/hr/employees/options').then((r) => r.data.data),
  list: (params?: EmployeeListParams) =>
    client.get<PaginatedResponse<Employee>>('/hr/employees', { params }).then((r) => r.data),

  statusCounts: (params?: Omit<EmployeeListParams, 'status'>) =>
    client
      .get<ApiSuccess<EmployeeStatusCounts>>('/hr/employees/status-counts', { params })
      .then((r) => r.data.data),

  show: (id: string) =>
    client.get<ApiSuccess<Employee>>(`/hr/employees/${id}`).then((r) => r.data.data),

  create: (data: CreateEmployeeData) =>
    client.post<ApiSuccess<Employee>>('/hr/employees', data).then((r) => r.data.data),

  update: (id: string, data: UpdateEmployeeData) =>
    client.put<ApiSuccess<Employee>>(`/hr/employees/${id}`, data).then((r) => r.data.data),

  delete: (id: string) => client.delete(`/hr/employees/${id}`),

  restore: (id: string) => client.patch(`/hr/employees/${id}/restore`),

  separate: (id: string, data: SeparateData) =>
    client
      .patch<ApiSuccess<Employee>>(`/hr/employees/${id}/separate`, data)
      .then((r) => r.data.data),

  uploadPhoto: (id: string, file: File) => {
    const fd = new FormData();
    fd.append('photo', file);
    return client
      .post<{ data: { photo_url: string } }>(`/hr/employees/${id}/photo`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data.data);
  },

  deletePhoto: (id: string) => client.delete(`/hr/employees/${id}/photo`),
};
