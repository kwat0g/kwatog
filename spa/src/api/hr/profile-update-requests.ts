import { client } from '../client';
import type { PaginatedResponse, ApiSuccess } from '@/types';

export type ProfileUpdateRequestStatus =
  | 'pending'
  | 'pending_finance'
  | 'approved'
  | 'rejected';

export interface ProfileUpdateReviewItem {
  id: string;
  status: ProfileUpdateRequestStatus;
  requires_finance: boolean;
  changes: Record<string, string | null>;
  note: string | null;
  employee: {
    id: string;
    employee_no: string;
    full_name: string;
    department: { id: string; name: string } | null;
  } | null;
  requester: { id: string; name: string; email: string } | null;
  reviewer: { id: string; name: string } | null;
  reviewed_at: string | null;
  review_remarks: string | null;
  finance_reviewer: { id: string; name: string } | null;
  finance_reviewed_at: string | null;
  finance_remarks: string | null;
  created_at: string | null;
}

export const profileUpdateRequestsApi = {
  list: (params?: { status?: ProfileUpdateRequestStatus; page?: number; per_page?: number }) =>
    client
      .get<PaginatedResponse<ProfileUpdateReviewItem>>('/hr/profile-update-requests', { params })
      .then((r) => r.data),

  review: (id: string, action: 'approve' | 'reject', remarks?: string) =>
    client
      .patch<ApiSuccess<ProfileUpdateReviewItem>>(
        `/hr/profile-update-requests/${id}/review`,
        { action, remarks: remarks ?? null },
      )
      .then((r) => (r.data as { data?: ProfileUpdateReviewItem }).data ?? (r.data as unknown as ProfileUpdateReviewItem)),

  // Task SS2 — Finance leg for bank-account changes (HR + Finance dual approval).
  financeReview: (id: string, action: 'approve' | 'reject', remarks?: string) =>
    client
      .patch<ApiSuccess<ProfileUpdateReviewItem>>(
        `/hr/profile-update-requests/${id}/finance-review`,
        { action, remarks: remarks ?? null },
      )
      .then((r) => (r.data as { data?: ProfileUpdateReviewItem }).data ?? (r.data as unknown as ProfileUpdateReviewItem)),
};
