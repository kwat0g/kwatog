/**
 * ADV10 — Order-to-Cash chain-step builder for Sales Orders.
 *
 * The API endpoint /b2b/customer/orders/{id}/chain provides the authoritative
 * chain data. This builder is used as a fallback when the API is unavailable
 * or for instant rendering from local state. The API response shape matches
 * the ChainStep[] interface.
 *
 * Chain stages:
 *   Order Entered → MRP Planned → In Production → QC Outgoing → Delivered → Invoiced
 */
import type { ChainStep } from '@/types/chain';

const ALL_STEPS = [
  'order_entered',
  'mrp_planned',
  'in_production',
  'qc_outgoing',
  'delivered',
  'invoiced',
] as const;

const STATUS_MAP: Record<string, string> = {
  draft:             'order_entered',
  confirmed:         'mrp_planned',
  in_production:     'in_production',
  partial_production:'in_production',
  ready_for_delivery:'qc_outgoing',
  partial_delivered: 'delivered',
  delivered:         'delivered',
  invoiced:          'invoiced',
  paid:              'invoiced',
  closed:            'invoiced',
  cancelled:         'invoiced',
};

const STEP_LABELS: Record<string, string> = {
  order_entered:  'Order Entered',
  mrp_planned:    'MRP Planned',
  in_production:  'In Production',
  qc_outgoing:    'QC Outgoing',
  delivered:      'Delivered',
  invoiced:       'Invoiced',
};

export function buildSalesOrderChain(status: string, createdAt?: string, _mrpPlanId?: number | null): ChainStep[] {
  const activeStep = STATUS_MAP[status] ?? 'order_entered';
  const activeIdx = ALL_STEPS.indexOf(activeStep as typeof ALL_STEPS[number]);

  return ALL_STEPS.map((key, i) => {
    let state: 'done' | 'active' | 'pending';
    if (i < activeIdx) {
      state = 'done';
    } else if (i === activeIdx) {
      state = 'active';
    } else {
      state = 'pending';
    }

    // Special case: if cancelled, mark all as pending
    if (status === 'cancelled') {
      state = 'pending';
    }

    return {
      key,
      label: STEP_LABELS[key] ?? key,
      state,
      date: i === 0 && createdAt ? createdAt.slice(0, 10) : undefined,
    };
  });
}
