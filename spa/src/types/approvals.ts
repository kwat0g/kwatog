/**
 * Series F — Task F2. Approval board types.
 */

export type ApprovalKind = 'leave' | 'pr' | 'po' | 'loan' | 'payroll';

export interface ApprovalCardActive {
  id: string;
  type: ApprovalKind;
  number: string;
  link: string;
  step_order: number;
  role_slug: string;
  since: string;
  age_hours: number;
  amount: string | null;
  summary: string;
  /** ADV4 — original requester of the approvable, with their role. */
  requester?: { name: string; role: { name: string; slug: string } | null } | null;
}

export interface ApprovalCardActioned {
  id: string;
  type: ApprovalKind;
  number: string;
  link: string;
  action: 'approved' | 'rejected';
  acted_at: string;
  remarks: string;
  amount: string | null;
  summary: string;
  /** ADV4 — the user who approved/rejected, with their role. */
  actor?: { id: string; name: string; role: { name: string; slug: string } | null } | null;
}

export interface ApprovalBoard {
  my_action: ApprovalCardActive[];
  awaiting_others: ApprovalCardActive[];
  approved: ApprovalCardActioned[];
  rejected: ApprovalCardActioned[];
  summary: {
    my_action: number;
    awaiting_others: number;
    approved: number;
    rejected: number;
  };
}

export interface ApprovalBoardResponse {
  data: ApprovalBoard;
}

export interface ApprovalBoardParams {
  type?: ApprovalKind;
}
