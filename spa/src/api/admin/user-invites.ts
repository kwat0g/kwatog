import { client } from '../client';
import type { ApiSuccess, ListParams, PaginatedResponse } from '@/types';

/** WS-A.1 — Self-service portal account invite. Token never leaves server. */
export interface UserInvite {
  id: string;
  email: string;
  expires_at: string | null;
  used_at: string | null;
  is_pending: boolean;
  is_expired: boolean;
  created_at: string | null;
  employee?: {
    id: string;
    employee_no: string;
    full_name: string;
  };
  role?: {
    id: string;
    name: string;
    slug: string;
  } | null;
  inviter?: {
    id: string;
    name: string;
  } | null;
}

export interface InviteListParams extends ListParams {
  status?: 'pending' | 'used' | 'expired' | 'revoked' | 'all';
}

export interface CreateInvitePayload {
  employee_id: string;
  role_id?: string;
  email: string;
}

export interface AcceptInvitePayload {
  token: string;
  name: string;
  password: string;
  password_confirmation: string;
}

export const userInvitesApi = {
  list: (params?: InviteListParams) =>
    client
      .get<PaginatedResponse<UserInvite>>('/auth/invites', { params })
      .then((r) => r.data),

  create: (data: CreateInvitePayload) =>
    client
      .post<ApiSuccess<UserInvite>>('/auth/invites', data)
      .then((r) => r.data.data),

  revoke: (id: string) => client.delete(`/auth/invites/${id}`),

  /** Public — no auth required. Accepts the invite token, sets password,
   *  returns the freshly provisioned user wrapped in an `ApiSuccess`. */
  accept: (data: AcceptInvitePayload) =>
    client.post<ApiSuccess<unknown>>('/auth/invites/accept', data).then((r) => r.data.data),
};
