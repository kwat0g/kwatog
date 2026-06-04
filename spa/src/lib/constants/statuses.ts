/**
 * Centralized status/priority filter options for purchasing pages.
 * Used by FilterBar `options` arrays — each entry has a `label` (display text)
 * and a `value` (sent to the API).
 */

export const PR_STATUSES = [
  { label: 'Draft',     value: 'draft' },
  { label: 'Pending',   value: 'pending' },
  { label: 'Approved',  value: 'approved' },
  { label: 'Rejected',  value: 'rejected' },
  { label: 'Converted', value: 'converted' },
  { label: 'Cancelled', value: 'cancelled' },
] as const;

export const PO_STATUSES = [
  { label: 'Draft',              value: 'draft' },
  { label: 'Pending Approval',   value: 'pending_approval' },
  { label: 'Approved',           value: 'approved' },
  { label: 'Sent',               value: 'sent' },
  { label: 'Partially Received', value: 'partially_received' },
  { label: 'Received',           value: 'received' },
  { label: 'Closed',             value: 'closed' },
  { label: 'Cancelled',          value: 'cancelled' },
] as const;

export const PR_PRIORITIES = [
  { label: 'Normal',   value: 'normal' },
  { label: 'Urgent',   value: 'urgent' },
  { label: 'Critical', value: 'critical' },
] as const;
