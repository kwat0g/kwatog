/**
 * Sprint P1 — centralized chain-step builder for Goods Receipt Notes.
 *
 * Procure-to-Pay chain (GRN scope):
 *   PO → GRN Created → QC → Stock Updated → Bill → Paid
 */
import type { ChainStep } from '@/types/chain';
import type { GoodsReceiptNote } from '@/types/inventory';
import { formatDate } from '@/lib/formatDate';

export function buildGrnChain(grn: GoodsReceiptNote): ChainStep[] {
  const status = grn.status;
  const isAccepted = status === 'accepted' || status === 'partial_accepted';
  return [
    {
      key: 'po',
      label: grn.purchase_order ? `PO ${grn.purchase_order.po_number}` : 'PO',
      state: grn.purchase_order ? 'done' : 'pending',
    },
    {
      key: 'grn',
      label: 'GRN Created',
      state: 'done',
      date: formatDate(grn.received_date),
    },
    {
      key: 'qc',
      label: 'QC',
      state: status === 'pending_qc' ? 'active' : status === 'rejected' ? 'pending' : 'done',
    },
    {
      key: 'stock',
      label: 'Stock Updated',
      state: isAccepted ? 'done' : 'pending',
      date: isAccepted && grn.accepted_at ? formatDate(grn.accepted_at) : undefined,
    },
    { key: 'bill', label: 'Bill', state: 'pending' },
    { key: 'paid', label: 'Paid', state: 'pending' },
  ];
}
