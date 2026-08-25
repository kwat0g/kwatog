import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeSkill, AssignSkillData } from '@/types/hr';

export const employeeSkillsApi = {
 list: (employeeId: string, params?: ListParams) =>
 client.get<PaginatedResponse<EmployeeSkill>>(`/hr/employees/${employeeId}/skills`, { params }).then((r) => r.data),
 assign: (employeeId: string, data: AssignSkillData & { certificate?: File | null }) => {
  if (!data.certificate) {
   return client.post<ApiSuccess<EmployeeSkill>>(`/hr/employees/${employeeId}/skills`, data).then((r) => r.data.data);
  }
  const form = new FormData();
  Object.entries(data).forEach(([key, value]) => {
   if (value !== undefined && value !== null && key !== 'certificate') form.append(key, String(value));
  });
  form.append('certificate', data.certificate);
  return client.post<ApiSuccess<EmployeeSkill>>(`/hr/employees/${employeeId}/skills`, form).then((r) => r.data.data);
 },
 update: (recordId: string, data: Partial<AssignSkillData> & { certificate?: File | null }) => {
  if (!data.certificate) {
   return client.patch<ApiSuccess<EmployeeSkill>>(`/hr/employee-skills/${recordId}`, data).then((r) => r.data.data);
  }
  const form = new FormData();
  form.append('_method', 'PATCH');
  Object.entries(data).forEach(([key, value]) => {
   if (value !== undefined && value !== null && key !== 'certificate') form.append(key, String(value));
  });
  form.append('certificate', data.certificate);
  return client.post<ApiSuccess<EmployeeSkill>>(`/hr/employee-skills/${recordId}`, form).then((r) => r.data.data);
 },
  remove: (recordId: string) =>
  client.delete(`/hr/employee-skills/${recordId}`),
  restore: (recordId: string) =>
  client.patch(`/hr/employee-skills/${recordId}/restore`),
};
