import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeSkill, AssignSkillData } from '@/types/hr';

export const employeeSkillsApi = {
 list: (employeeId: string, params?: ListParams) =>
 client.get<PaginatedResponse<EmployeeSkill>>(`/hr/employees/${employeeId}/skills`, { params }).then((r) => r.data),
 assign: (employeeId: string, data: AssignSkillData) =>
 client.post<ApiSuccess<EmployeeSkill>>(`/hr/employees/${employeeId}/skills`, data).then((r) => r.data.data),
 update: (recordId: string, data: Partial<AssignSkillData>) =>
 client.patch<ApiSuccess<EmployeeSkill>>(`/hr/employee-skills/${recordId}`, data).then((r) => r.data.data),
  remove: (recordId: string) =>
  client.delete(`/hr/employee-skills/${recordId}`),
  restore: (recordId: string) =>
  client.patch(`/hr/employee-skills/${recordId}/restore`),
};