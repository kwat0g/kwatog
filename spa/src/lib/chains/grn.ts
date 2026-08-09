/**
 * Sprint P1 — centralized chain-step builder for Goods Receipt Notes.
 *
 * Incoming receipt lifecycle:
 * PO → GRN Created → QC → Stock Updated
 *
 * @deprecated 2026-08-08 — superseded on the GRN detail page by the compact
 * cross-document `buildP2pChain` (PR → PO → GRN → Bill → Paid). Kept as dead
 * code per the keep-code policy; re-enable if a QC-detail stepper is wanted
 * again (the accepted-receipt convention it encodes still drives p2p.ts).
 */
import type { ChainStep } from '@/types/chain';
import type { GoodsReceiptNote } from '@/types/inventory';
import { formatDate } from '@/lib/formatDate';export function buildGrnChain(grn: GoodsReceiptNote): ChainStep[] {
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
  label: status === 'draft' ? 'Expected GRN' : 'GRN Created',
  // A draft GRN is an expectation, not a receipt — the step is only done
  // once goods land (pending_qc) or beyond.
  state: status === 'draft' ? 'active' : 'done',
  date: status === 'draft' ? undefined : formatDate(grn.received_date),
 },
 {
  key: 'qc',
  label: 'QC',
  state: status === 'pending_qc' ? 'active' : status === 'draft' || status === 'rejected' ? 'pending' : 'done',
 },
 {
  key: 'stock',
  label: 'Stock Updated',
  state: isAccepted ? 'done' : 'pending',
  date: isAccepted && grn.accepted_at ? formatDate(grn.accepted_at) : undefined,
 },
 ];
}
