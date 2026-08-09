import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeDocument } from '@/types/hr';

export const employeeDocumentApi = {
 options: (employeeId: string) =>
 client.get<{ data: { document_types: Array<{ value: string; label: string }> } }>(`/hr/employees/${employeeId}/documents/options`).then((r) => r.data.data),
 list: (employeeId: string, params?: ListParams) =>
 client.get<PaginatedResponse<EmployeeDocument>>(`/hr/employees/${employeeId}/documents`, { params }).then((r) => r.data),
 upload: (employeeId: string, data: FormData) =>
 client.post<ApiSuccess<EmployeeDocument>>(`/hr/employees/${employeeId}/documents`, data, {
 headers: { 'Content-Type': 'multipart/form-data' },
 }).then((r) => r.data.data),
  delete: (employeeId: string, documentId: string) =>
  client.delete(`/hr/employees/${employeeId}/documents/${documentId}`),
  restore: (employeeId: string, documentId: string) =>
  client.patch(`/hr/employees/${employeeId}/documents/${documentId}/restore`),
  downloadUrl: (documentId: string) =>
 `/api/v1/hr/employee-documents/${documentId}/download`,
};
