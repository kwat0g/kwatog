import { client } from './client';
import type {
 ApprovalBoardParams,
 ApprovalBoardResponse,
 ApprovalKind,
} from '@/types/approvals';

/**
 * Series F — Task F2. Approval board API client.
 *
 * The board endpoint is read-only; approve/reject mutations remain on
 * the per-entity controllers (leave, PR, PO, loan, payroll) and are
 * triggered by navigating to the source record.
 */
export const approvalsApi = {
 options: () => client.get<{ data: { kinds: Array<{ value: ApprovalKind; label: string }>; overdue_hours: number } }>('/approvals/options').then((r) => r.data.data),
 board: (params?: ApprovalBoardParams) =>
 client
 .get<ApprovalBoardResponse>('/approvals/board', {
 params: { type: params?.type },
 })
 .then((r) => r.data.data),
};
