import type { DeliveryNegotiation } from '@/types/crm';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { formatDate, formatDateTime } from '@/lib/formatDate';

export function DeliveryNegotiationHistory({ proposals }: { proposals?: DeliveryNegotiation[] }) {
  if (!proposals?.length) return null;
  return (
    <details className="mt-4 border-t border-default pt-3 text-sm">
      <summary className="cursor-pointer font-medium">
        Delivery negotiation history ({proposals.length})
      </summary>
      <ol className="mt-3 divide-y divide-default">
        {proposals.map((proposal, index) => (
          <li key={proposal.id} className="py-3 first:pt-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-medium">
                Round {proposals.length - index} ·{' '}
                {proposal.proposed_delivery_date
                  ? formatDate(proposal.proposed_delivery_date)
                  : '—'}
              </span>
              <Chip variant={chipVariantForStatus(proposal.status)}>
                {proposal.status === 'pending'
                  ? 'Awaiting customer response'
                  : proposal.status.replace(/_/g, ' ')}
              </Chip>
            </div>
            {proposal.notes && (
              <p className="mt-1 whitespace-pre-wrap text-muted">{proposal.notes}</p>
            )}
            <p className="mt-1 text-xs text-muted">
              Proposed {proposal.responded_at ? formatDateTime(proposal.responded_at) : '—'}
              {proposal.resolved_at ? ` · Resolved ${formatDateTime(proposal.resolved_at)}` : ''}
            </p>
          </li>
        ))}
      </ol>
    </details>
  );
}
