/**
 * 2026-08-08 — compact cross-document Order-to-Cash stepper.
 *
 * The 4-step view shows the whole chain from either end of the flow:
 * SO → Delivery → Invoice → Payment. Each step links to its source document so
 * a user on the Sales Order page can click straight through to the delivery or
 * the invoice — and a user on the invoice page sees the upstream chain.
 */
import type { ChainStep } from '@/types/chain';

export interface O2cChainDoc {
  id: string;
  number: string;
}

export interface O2cChainInvoice {
  id: string;
  invoice_number: string;
  status: string;
}

export interface O2cChainInput {
  so?: O2cChainDoc | null;
  soStatus?: string | null;
  delivery?: O2cChainDoc | null;
  /** Delivery lifecycle status — only delivered/confirmed satisfy the step. */
  deliveryStatus?: string | null;
  invoices: O2cChainInvoice[];
}

export function buildO2cChain(input: O2cChainInput): ChainStep[] {
  const { so, delivery, invoices } = input;
  const isSoCancelled = input.soStatus === 'cancelled';
  const deliveryStatus = input.deliveryStatus ?? null;
  const hasInvoices = invoices.length > 0;
  // Goods must have been shipped before invoicing; an invoice existing also
  // implies the delivery step is satisfied (mirrors the P2P GRN logic). A
  // cancelled delivery is stuck, not in progress — mirror the P2P rejected-GRN
  // convention.
  const deliverySatisfied =
    deliveryStatus === 'delivered' ||
    deliveryStatus === 'confirmed' ||
    hasInvoices;
  const isCancelled = isSoCancelled || deliveryStatus === 'cancelled';
  // The draft invoice auto-creates on delivery confirm — until then the
  // invoice step only turns active once goods are delivered/confirmed.
  const invoiceReady =
    deliveryStatus === 'delivered' || deliveryStatus === 'confirmed';
  const isPaid = invoices.some((inv) => inv.status === 'paid');
  const firstInvoice = invoices[0];
  // Draft invoices have no number yet (assigned at finalize) — never render a
  // bare "Invoice  issued".
  const invoiceLabel =
    firstInvoice?.invoice_number && firstInvoice.invoice_number.trim() !== ''
      ? firstInvoice.invoice_number
      : '(draft)';

  return [
    {
      key: 'so',
      label: so ? `SO ${so.number}` : 'SO',
      state: isSoCancelled ? 'rejected' : so ? 'done' : 'pending',
      href: so ? `/crm/sales-orders/${so.id}` : undefined,
      description: isSoCancelled
        ? `Sales order ${so?.number ?? ''} was cancelled`
        : so
          ? `Sales order ${so.number}`
          : 'No linked sales order',
    },
    {
      key: 'delivery',
      label: 'Delivery',
      state: isSoCancelled
        ? 'skipped'
        : deliveryStatus === 'cancelled'
          ? 'rejected'
          : deliverySatisfied
            ? 'done'
            : delivery
              ? 'active'
              : 'pending',
      href: delivery ? `/supply-chain/deliveries/${delivery.id}` : undefined,
      description: isSoCancelled
        ? 'Delivery skipped because the sales order was cancelled'
        : deliveryStatus === 'cancelled'
          ? 'Delivery cancelled — downstream invoicing is skipped'
          : deliverySatisfied
            ? delivery
              ? `Goods shipped on ${delivery.number}`
              : 'Goods shipped — delivery not linked on this invoice'
            : delivery
              ? 'Delivery in progress — awaiting shipment & customer confirmation'
              : 'No delivery yet — created once the order is produced',
    },
    {
      key: 'invoice',
      label: 'Invoice',
      state: isCancelled ? 'skipped' : hasInvoices ? 'done' : invoiceReady ? 'active' : 'pending',
      href: firstInvoice ? `/accounting/invoices/${firstInvoice.id}` : undefined,
      description: isCancelled
        ? 'Invoice skipped because the upstream order or delivery was cancelled'
        : hasInvoices
          ? `Invoice ${invoiceLabel} issued`
          : invoiceReady
            ? 'Draft invoice auto-creates when the delivery is confirmed'
            : 'Awaiting delivery',
    },
    {
      key: 'payment',
      label: 'Payment',
      state: isCancelled ? 'skipped' : isPaid ? 'done' : hasInvoices ? 'active' : 'pending',
      href: firstInvoice ? `/accounting/invoices/${firstInvoice.id}` : undefined,
      description: isCancelled
        ? 'Payment skipped because the upstream order or delivery was cancelled'
        : isPaid
          ? 'Invoice settled — the AR collection was posted'
          : hasInvoices
            ? 'Record a collection on the invoice to complete the chain'
            : 'Awaiting invoice',
    },
  ];
}
