import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Department, CreateDepartmentData, UpdateDepartmentData } from '@/types/hr';

export const departmentsApi = {
 list: (params?: ListParams) =>
 client.get<PaginatedResponse<Department>>('/hr/departments', { params }).then((r) => r.data),

tree: (params?: ListParams) =>
  client.get<{ data: Department[] }>('/hr/departments/tree', { params }).then((r) => r.data.data),

 show: (id: string) =>
 client.get<ApiSuccess<Department>>(`/hr/departments/${id}`).then((r) => r.data.data),

 create: (data: CreateDepartmentData) =>
 client.post<ApiSuccess<Department>>('/hr/departments', data).then((r) => r.data.data),

 update: (id: string, data: UpdateDepartmentData) =>
 client.put<ApiSuccess<Department>>(`/hr/departments/${id}`, data).then((r) => r.data.data),

  delete: (id: string) => client.delete(`/hr/departments/${id}`),

  restore: (id: string) => client.patch(`/hr/departments/${id}/restore`),
};
