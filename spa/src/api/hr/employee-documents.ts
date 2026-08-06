import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeDocument } from '@/types/hr';

export const employeeDocumentApi = {
 list: (employeeId: string, params?: ListParams) =>
 client.get<PaginatedResponse<EmployeeDocument>>(`/hr/employees/${employeeId}/documents`, { params }).then((r) => r.data),
 upload: (employeeId: string, data: FormData) =>
 client.post<ApiSuccess<EmployeeDocument>>(`/hr/employees/${employeeId}/documents`, data, {
 headers: { 'Content-Type': 'multipart/form-data' },
 }).then((r) => r.data.data),
  delete: (documentId: string) =>
  client.delete(`/hr/employees/documents/${documentId}`),
  restore: (documentId: string) =>
  client.patch(`/hr/employees/documents/${documentId}/restore`),
  downloadUrl: (documentId: string) =>
 `/api/v1/hr/employee-documents/${documentId}/download`,
};