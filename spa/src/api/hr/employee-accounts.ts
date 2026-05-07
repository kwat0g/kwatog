import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type {
  EmployeeAccountStatus,
  ProvisionAccountPayload,
  BulkProvisionResponse,
} from '@/types/hr';

/**
 * U1 — Employee system account lifecycle.
 * Backend routes are mounted at /hr/employees/{id}/...
 * IDs are HashID strings on the wire.
 */
export const employeeAccountsApi = {
  status: (employeeId: string) =>
    client
      .get<ApiSuccess<EmployeeAccountStatus>>(`/hr/employees/${employeeId}/account-status`)
      .then((r) => (r.data as { data?: EmployeeAccountStatus } & EmployeeAccountStatus).data ?? (r.data as unknown as EmployeeAccountStatus)),

  provision: (employeeId: string, payload?: ProvisionAccountPayload) =>
    client
      .post<{ message: string; data: { id: string; email: string; name: string } }>(
        `/hr/employees/${employeeId}/provision-account`,
        payload ?? {},
      )
      .then((r) => r.data),

  deactivate: (employeeId: string) =>
    client.post(`/hr/employees/${employeeId}/deactivate-account`),

  resetPassword: (employeeId: string) =>
    client
      .patch<{ message: string; sent_to: string | null }>(
        `/hr/employees/${employeeId}/reset-password`,
      )
      .then((r) => r.data),

  bulkProvision: (employeeIds: string[], sendWelcome = true) =>
    client
      .post<BulkProvisionResponse>('/hr/employees/bulk-provision-accounts', {
        employee_ids: employeeIds,
        send_welcome: sendWelcome,
      })
      .then((r) => r.data),
};
