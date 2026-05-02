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
  daily_rate?: string;
  bank_name?: string;
  bank_account_no?: string;
}

export type UpdateEmployeeData = Partial<CreateEmployeeData> & { status?: string };

export interface SeparateData {
  separation_reason: 'resigned' | 'terminated' | 'retired' | 'end_of_contract';
  separation_date: string;
  remarks?: string;
}

export const employeesApi = {
  list: (params?: EmployeeListParams) =>
    client.get<PaginatedResponse<Employee>>('/hr/employees', { params }).then((r) => r.data),

  show: (id: string) =>
    client.get<ApiSuccess<Employee>>(`/hr/employees/${id}`).then((r) => r.data.data),

  create: (data: CreateEmployeeData) =>
    client.post<ApiSuccess<Employee>>('/hr/employees', data).then((r) => r.data.data),

  update: (id: string, data: UpdateEmployeeData) =>
    client.put<ApiSuccess<Employee>>(`/hr/employees/${id}`, data).then((r) => r.data.data),

  delete: (id: string) => client.delete(`/hr/employees/${id}`),

  separate: (id: string, data: SeparateData) =>
    client.patch<ApiSuccess<Employee>>(`/hr/employees/${id}/separate`, data).then((r) => r.data.data),
};
