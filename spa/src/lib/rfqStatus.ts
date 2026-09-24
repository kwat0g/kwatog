import type { ChipVariant } from '@/components/ui/Chip';

/**
 * Labels and chip colours for Supplier RFQ statuses. The shared
 * `chipVariantForStatus()` has no entries for these, so every RFQ state
 * rendered grey; one map keeps the RFQ pages consistent.
 */
type Meta = { label: string; variant: ChipVariant };

const RFQ: Record<string, Meta> = {
  draft: { label: 'Draft', variant: 'neutral' },
  open: { label: 'Open for quotes', variant: 'info' },
  closed: { label: 'Ready to award', variant: 'warning' },
  awarded: { label: 'Awarded', variant: 'success' },
  cancelled: { label: 'Cancelled', variant: 'neutral' },
};

const INVITATION: Record<string, Meta> = {
  invited: { label: 'Not opened yet', variant: 'neutral' },
  viewed: { label: 'Opened', variant: 'info' },
  submitted: { label: 'Quote submitted', variant: 'success' },
  awarded: { label: 'Awarded', variant: 'success' },
  not_awarded: { label: 'Not selected', variant: 'neutral' },
};

const QUOTE: Record<string, Meta> = {
  draft: { label: 'Draft — not sent', variant: 'warning' },
  submitted: { label: 'Submitted', variant: 'success' },
  awarded: { label: 'Awarded', variant: 'success' },
  not_awarded: { label: 'Not selected', variant: 'neutral' },
};

const fallback = (status: string): Meta => ({ label: status.replace(/_/g, ' '), variant: 'neutral' });

export const rfqStatus = (status: string): Meta => RFQ[status] ?? fallback(status);
export const invitationStatus = (status: string): Meta => INVITATION[status] ?? fallback(status);
export const quoteStatus = (status: string): Meta => QUOTE[status] ?? fallback(status);
