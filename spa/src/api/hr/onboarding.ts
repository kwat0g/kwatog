import { client } from '../client';
import type { EmployeeOnboarding } from '@/types/hr';

/**
 * U4 — Employee onboarding workflow.
 *
 * Note: the backend returns an `EmployeeOnboardingResource` which Laravel
 * auto-wraps in `{ data: ... }`. We unwrap one level here so the consumer
 * gets the flat `EmployeeOnboarding` shape directly.
 */
type Wrapped<T> = { data: T } & Partial<T>;

const unwrap = <T,>(payload: Wrapped<T>): T => (payload?.data ?? (payload as unknown as T));

export const onboardingApi = {
  show: (employeeId: string) =>
    client
      .get<Wrapped<EmployeeOnboarding>>(`/hr/employees/${employeeId}/onboarding`)
      .then((r) => unwrap<EmployeeOnboarding>(r.data)),

  recompute: (employeeId: string) =>
    client
      .post<Wrapped<EmployeeOnboarding>>(`/hr/employees/${employeeId}/onboarding/recompute`)
      .then((r) => unwrap<EmployeeOnboarding>(r.data)),
};
