import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeProperty } from '@/types/hr';

export const employeePropertyApi = {
 list: (employeeId: string, params?: ListParams) =>
 client.get<PaginatedResponse<EmployeeProperty>>(`/hr/employees/${employeeId}/property`, { params }).then((r) => r.data),
 create: (employeeId: string, data: CreateEmployeePropertyData) =>
 client.post<ApiSuccess<EmployeeProperty>>(`/hr/employees/${employeeId}/property`, data).then((r) => r.data.data),
 update: (propertyId: string, employeeId: string, data: UpdateEmployeePropertyData) =>
 client.put<ApiSuccess<EmployeeProperty>>(`/hr/employees/${employeeId}/property/${propertyId}`, data).then((r) => r.data.data),
 show: (propertyId: string, employeeId: string) =>
 client.get<ApiSuccess<EmployeeProperty>>(`/hr/employees/${employeeId}/property/${propertyId}`).then((r) => r.data.data),
  delete: (propertyId: string, employeeId: string) =>
  client.delete(`/hr/employees/${employeeId}/property/${propertyId}`),
  restore: (propertyId: string, employeeId: string) =>
  client.patch(`/hr/employees/${employeeId}/property/${propertyId}/restore`),
};

export interface CreateEmployeePropertyData {
 item_name: string;
 description?: string;
 quantity: number;
 replacement_unit_cost?: string;
 date_issued: string;
 date_returned?: string | null;
 status?: 'issued' | 'returned' | 'lost';
}

export type UpdateEmployeePropertyData = Partial<CreateEmployeePropertyData>;