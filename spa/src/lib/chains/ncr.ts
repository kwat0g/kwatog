/**
 * Sprint P1 — centralized chain-step builder for Non-Conformance Reports.
 *
 * Quality chain:
 * Raised → QC Head Review → Disposition → Corrective Action → Closed
 */
import type { ChainStep } from '@/types/chain';
import type { Ncr } from '@/types/quality';
import { formatDateIso } from '@/lib/formatDate';

export function buildNcrChain(ncr: Ncr): ChainStep[] {
  const hasActions = (ncr.actions?.length ?? 0) > 0;
  const isClosed = ncr.status === 'closed';
  const isCancelled = ncr.status === 'cancelled';

  if (isCancelled) {
    return [
      {
        key: 'opened',
        label: 'Raised',
        state: 'done',
        date: ncr.created_at ? formatDateIso(ncr.created_at) : undefined,
      },
      {
        key: 'cancelled',
        label: 'Cancelled',
        state: 'done',
        date: ncr.updated_at ? formatDateIso(ncr.updated_at) : undefined,
      },
    ];
  }

  return [
    {
      key: 'opened',
      label: 'Raised',
      state: 'done',
      date: ncr.created_at ? formatDateIso(ncr.created_at) : undefined,
    },
    {
      key: 'review',
      label: 'QC Head Review',
      state:
        ncr.disposition || hasActions || isClosed
          ? 'done'
          : ncr.status === 'open'
            ? 'active'
            : 'pending',
    },
    {
      key: 'disposition',
      label: 'Disposition',
      state: ncr.disposition ? 'done' : isClosed ? 'done' : 'pending',
    },
    {
      key: 'actions',
      label: 'Corrective Action',
      state: hasActions ? 'done' : ncr.disposition ? 'active' : 'pending',
    },
    {
      key: 'closed',
      label: 'Closed',
      state: isClosed ? 'done' : 'pending',
      date: ncr.closed_at ? formatDateIso(ncr.closed_at) : undefined,
    },
  ];
}
