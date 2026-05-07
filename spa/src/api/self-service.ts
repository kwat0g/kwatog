import { client } from './client';
import type {
  SelfServiceHome,
  SelfServiceLoansResponse,
  SelfServiceProfile,
  ProfileUpdateRequestRecord,
} from '@/types/self-service';

/** U3 — Self-service portal endpoints (always scoped to current user). */
export const selfServiceApi = {
  home: () =>
    client.get<{ data: SelfServiceHome }>('/hr/self-service/home').then((r) => r.data.data),

  loans: () =>
    client
      .get<{ data: SelfServiceLoansResponse }>('/hr/self-service/loans')
      .then((r) => r.data.data),

  applyLoan: (data: { loan_type: string; amount: number; periods: number; reason?: string }) =>
    client
      .post<{ message: string; data: { id: string } }>('/hr/self-service/loans', data)
      .then((r) => r.data),

  profile: () =>
    client.get<{ data: SelfServiceProfile }>('/hr/self-service/profile').then((r) => r.data.data),

  requestProfileUpdate: (changes: Record<string, string | null>, note?: string) =>
    client
      .post<{ message: string; data: { id: string; status: string } }>(
        '/hr/self-service/profile/request-update',
        { changes, note },
      )
      .then((r) => r.data),

  profileUpdateRequests: () =>
    client
      .get<{ data: ProfileUpdateRequestRecord[] }>('/hr/self-service/profile/update-requests')
      .then((r) => r.data.data),
};
