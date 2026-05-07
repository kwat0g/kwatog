import { client } from '../client';
import type { EmployeeOnboarding } from '@/types/hr';

/** U4 — Employee onboarding workflow. */
export const onboardingApi = {
  show: (employeeId: string) =>
    client
      .get<EmployeeOnboarding>(`/hr/employees/${employeeId}/onboarding`)
      .then((r) => r.data),

  recompute: (employeeId: string) =>
    client
      .post<EmployeeOnboarding>(`/hr/employees/${employeeId}/onboarding/recompute`)
      .then((r) => r.data),
};
