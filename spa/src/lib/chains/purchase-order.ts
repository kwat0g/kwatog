/**
 * Sprint P1 — centralized chain-step builder for Purchase Orders.
 *
 * Procure-to-Pay chain:
 *   PR → PO Created → PO Approved → Sent → GRN Received → Bill Created → Payment → Closed
 */
import type { ChainStep } from '@/types/chain';
import type { PurchaseOrder, PurchaseOrderStatus } from '@/types/purchasing';
import { formatDate } from '@/lib/formatDate';

const ORDER: PurchaseOrderStatus[] = [
  'draft',
  'pending_approval',
  'approved',
  'sent',
  'partially_received',
  'received',
  'closed',
];

export function buildPurchaseOrderChain(po: PurchaseOrder): ChainStep[] {
  const status = po.status;
  const isAtOrPast = (target: PurchaseOrderStatus): boolean => {
    if (status === 'cancelled') return false;
    return ORDER.indexOf(status) >= ORDER.indexOf(target);
  };
  const stateOf = (
    target: PurchaseOrderStatus,
    activeWhen?: PurchaseOrderStatus,
  ): 'done' | 'active' | 'pending' => {
    if (status === 'cancelled') return 'pending';
    if (activeWhen && status === activeWhen) return 'active';
    return isAtOrPast(target) ? 'done' : 'pending';
  };
  const grns = po.goods_receipt_notes ?? [];
  const bills = po.bills ?? [];
  const hasGrn = grns.length > 0;
  const hasBill = bills.length > 0;
  const hasPaidBill = bills.some((b) => b.status === 'paid');

  return [
    {
      key: 'pr',
      label: po.purchase_request ? `PR ${po.purchase_request.pr_number}` : 'PR',
      date: po.purchase_request ? '✓' : undefined,
      state: po.purchase_request ? 'done' : 'pending',
    },
    {
      key: 'po',
      label: 'PO Created',
      date: formatDate(po.date),
      state: 'done',
    },
    {
      key: 'approved',
      label: 'PO Approved',
      date: po.approved_at ? formatDate(po.approved_at) : undefined,
      state: stateOf('approved', 'pending_approval'),
    },
    {
      key: 'sent',
      label: 'Sent',
      date: po.sent_to_supplier_at ? formatDate(po.sent_to_supplier_at) : undefined,
      state: stateOf('sent', 'approved'),
    },
    {
      key: 'grn',
      label: 'GRN Received',
      state: hasGrn
        ? 'done'
        : status === 'sent' || status === 'partially_received'
          ? 'active'
          : 'pending',
    },
    {
      key: 'bill',
      label: 'Bill Created',
      state: hasBill ? 'done' : status === 'received' ? 'active' : 'pending',
    },
    {
      key: 'paid',
      label: 'Payment',
      state: hasPaidBill ? 'done' : hasBill ? 'active' : 'pending',
    },
    {
      key: 'closed',
      label: 'Closed',
      state: status === 'closed' ? 'done' : 'pending',
    },
  ];
}
