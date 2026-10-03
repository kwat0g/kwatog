import { client } from './client';

export interface ApprovalDelegationPerson {
  id: string;
  name: string;
  is_active: boolean;
}

export interface ApprovalDelegation {
  id: string;
  role_slug: string | null;
  starts_at: string;
  ends_at: string;
  reason: string | null;
  is_active: boolean;
  role_is_current: boolean;
  can_revoke: boolean;
  delegator: ApprovalDelegationPerson | null;
  delegate: ApprovalDelegationPerson | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ApprovalDelegationCandidate {
  id: string;
  name: string;
  role: string | null;
}

export interface CreateApprovalDelegation {
  delegate_user_id: string;
  role_slug: string;
  starts_at: string;
  ends_at: string;
  reason?: string;
}

export const approvalDelegationsApi = {
  list: () =>
    client.get<{ data: ApprovalDelegation[] }>('/approval-delegations').then((response) => response.data.data),
  candidates: (query: string) =>
    client
      .get<{ data: ApprovalDelegationCandidate[] }>('/approval-delegations/candidates', { params: { q: query } })
      .then((response) => response.data.data),
  create: (data: CreateApprovalDelegation) =>
    client.post<{ data: ApprovalDelegation }>('/approval-delegations', data).then((response) => response.data.data),
  revoke: (id: string) =>
    client.delete<{ data: ApprovalDelegation }>(`/approval-delegations/${id}`).then((response) => response.data.data),
};
