/**
 * 2026-08-08 — compact cross-document Procure-to-Pay stepper.
 *
 * The 5-step view shows the whole chain from either end of the flow:
 * PR → PO → GRN → Bill → Paid. Each step links to its source document so a
 * user on the PR page can click straight through to the PO, the receipt, or
 * the bill — and a user on the PO page sees downstream completion at a glance.
 */
import type { ChainStep } from '@/types/chain';
import { formatDate } from '@/lib/formatDate';

export interface P2pChainDoc {
  id: string;
  number: string;
  date?: string | null;
}

export interface P2pChainGrn {
  id: string;
  grn_number: string;
  status: string;
  received_date?: string | null;
}

export interface P2pChainBill {
  id: string;
  bill_number: string;
  status: string;
}

export interface P2pChainInput {
  pr?: P2pChainDoc | null;
  po?: P2pChainDoc | null;
  grns: P2pChainGrn[];
  bills: P2pChainBill[];
}

export function buildP2pChain(input: P2pChainInput): ChainStep[] {
  const { pr, po, grns, bills } = input;
  // A draft / pending-QC receipt is an expectation, not a receipt — mirror the
  // buildGrnChain convention: only accepted receipts advance the chain. A
  // rejected receipt is stuck (pending re-delivery), not in progress.
  const hasGrn = grns.length > 0;
  const hasReceivedGrn = grns.some((g) => g.status === 'accepted' || g.status === 'partial_accepted');
  const isRejected = grns.some((g) => g.status === 'rejected');
  const hasBill = bills.length > 0;
  // Goods must have been received for a bill to exist — if a bill is staged but
  // no receipt is linked (manual bill, or the GRN link isn't recorded), the GRN
  // step is satisfied rather than shown as outstanding.
  const grnSatisfied = hasReceivedGrn || (hasBill && !hasGrn);
  const isPaid = bills.some((b) => b.status === 'paid');
  const firstGrn = grns[0];
  const firstBill = bills[0];

  return [
    {
      key: 'pr',
      label: pr ? `PR ${pr.number}` : 'PR',
      date: pr?.date ? formatDate(pr.date) : undefined,
      state: pr ? 'done' : 'pending',
      href: pr ? `/purchasing/purchase-requests/${pr.id}` : undefined,
      description: pr ? `Purchase request ${pr.number}` : 'No linked purchase request',
    },
    {
      key: 'po',
      label: po ? `PO ${po.number}` : 'PO',
      date: po?.date ? formatDate(po.date) : undefined,
      state: po ? 'done' : 'pending',
      href: po ? `/purchasing/purchase-orders/${po.id}` : undefined,
      description: po
        ? `Purchase order ${po.number}`
        : 'No purchase order yet — one auto-creates when this PR is finally approved',
    },
    {
      key: 'grn',
      label: hasReceivedGrn ? 'GRN Received' : 'GRN',
      date: firstGrn?.received_date ? formatDate(firstGrn.received_date) : undefined,
      state: grnSatisfied ? 'done' : isRejected ? 'pending' : hasGrn || po ? 'active' : 'pending',
      href: firstGrn ? `/inventory/grn/${firstGrn.id}` : undefined,
      description: hasReceivedGrn
        ? `Goods receipt ${firstGrn?.grn_number} received`
        : isRejected
          ? 'Receipt rejected — awaiting re-delivery'
          : hasGrn
            ? 'Receipt created — awaiting QC / acceptance'
            : hasBill
              ? 'Goods received — receipt not linked on this bill'
              : po
                ? 'Goods not received yet — a GRN draft auto-creates when the PO is approved & sent'
                : 'Awaiting a purchase order',
    },
    {
      key: 'bill',
      label: 'Bill',
      state: hasBill ? 'done' : hasReceivedGrn ? 'active' : 'pending',
      href: firstBill ? `/accounting/bills/${firstBill.id}` : undefined,
      description: hasBill
        ? `Supplier bill ${firstBill.bill_number} staged`
        : hasReceivedGrn
          ? 'Draft AP bill auto-creates when the GRN is accepted'
          : 'Awaiting an accepted receipt',
    },
    {
      key: 'paid',
      label: 'Paid',
      state: isPaid ? 'done' : hasBill ? 'active' : 'pending',
      href: firstBill ? `/accounting/bills/${firstBill.id}` : undefined,
      description: isPaid
        ? 'Bill settled — the AP payment entry was posted'
        : hasBill
          ? 'Record a payment on the bill to complete the chain'
          : 'Awaiting a supplier bill',
    },
  ];
}
