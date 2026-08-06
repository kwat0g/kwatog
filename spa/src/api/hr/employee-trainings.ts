import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeTraining, AssignTrainingData } from '@/types/hr';

export const employeeTrainingsApi = {
 list: (employeeId: string, params?: ListParams) =>
 client.get<PaginatedResponse<EmployeeTraining>>(`/hr/employees/${employeeId}/trainings`, { params }).then((r) => r.data),
 assign: (employeeId: string, data: AssignTrainingData) =>
 client.post<ApiSuccess<EmployeeTraining>>(`/hr/employees/${employeeId}/trainings`, data).then((r) => r.data.data),
 complete: (recordId: string, data: { completed_at: string; certificate_path?: string | null }) =>
 client.patch<ApiSuccess<EmployeeTraining>>(`/hr/employee-trainings/${recordId}/complete`, data).then((r) => r.data.data),
 cancel: (recordId: string, reason?: string) =>
 client.patch<ApiSuccess<EmployeeTraining>>(`/hr/employee-trainings/${recordId}/cancel`, { reason }).then((r) => r.data.data),
};