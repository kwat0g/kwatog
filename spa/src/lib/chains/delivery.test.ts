import { describe, expect, it } from 'vitest';
import type { DeliveryProof, DeliveryStatus } from '@/types/supplyChain';
import { buildDeliveryChain } from './delivery';

function delivery(status: DeliveryStatus, fields: Partial<Parameters<typeof buildDeliveryChain>[0]> = {}) {
  return {
    status, scheduled_date: '2026-10-09', departed_at: null, received_at: null,
    confirmed_at: null, updated_at: '2026-10-09T08:00:00Z', receipt_photo_url: null, proofs: [], ...fields,
  };
}
function proof(proof_type: DeliveryProof['proof_type'], deleted_at: string | null = null): DeliveryProof {
  return {
    id: 'proof-1', proof_type, deleted_at, file_name: 'evidence.jpg', file_size: 10,
    mime_type: 'image/jpeg', is_image: true, notes: null, view_url: '/proof.jpg', uploader: null, uploaded_at: null,
  };
}

describe('shipment progress', () => {
  it.each(['scheduled', 'loading'] as const)('keeps %s at the shipment plan', (status) => {
    const steps = buildDeliveryChain(delivery(status));
    expect(steps.map((step) => step.label)).toEqual(['Shipment plan', 'Dispatched', 'Customer receipt', 'Confirmed']);
    expect(steps.map((step) => step.state)).toEqual(['active', 'pending', 'pending', 'pending']);
  });
  it('keeps a dispatched shipment waiting for driver receipt', () => {
    expect(buildDeliveryChain(delivery('in_transit')).map((step) => step.state)).toEqual(['done', 'active', 'pending', 'pending']);
  });
  it.each(['loading_photo', 'coc', 'other'] as const)('does not count %s as customer receipt on historical delivered records', (type) => {
    expect(buildDeliveryChain(delivery('delivered', { proofs: [proof(type)] })).map((step) => step.state)).toEqual(['done', 'done', 'active', 'pending']);
  });
  it('does not count an archived receipt', () => {
    expect(buildDeliveryChain(delivery('delivered', { proofs: [proof('photo', '2026-10-09T09:00:00Z')] }))[2].state).toBe('active');
  });
  it.each(['signed_dr', 'photo', 'customer_po_confirmation'] as const)('moves to office confirmation with an active %s', (type) => {
    expect(buildDeliveryChain(delivery('delivered', { proofs: [proof(type)] })).map((step) => step.state)).toEqual(['done', 'done', 'done', 'active']);
  });
  it('recognizes a legacy driver receipt photo', () => {
    expect(buildDeliveryChain(delivery('delivered', { receipt_photo_url: '/receipt.jpg' }))[3].state).toBe('active');
  });
  it('preserves completed confirmation even if old proof records are unavailable', () => {
    expect(buildDeliveryChain(delivery('confirmed')).every((step) => step.state === 'done')).toBe(true);
  });
  it.each(['return_pending', 'returned'] as const)('shows %s without claiming receipt or confirmation', (status) => {
    const steps = buildDeliveryChain(delivery(status));
    expect(steps.map((step) => step.key)).toEqual(['shipment_plan', 'dispatched', status]);
    expect(steps[2].state).toBe(status === 'return_pending' ? 'active' : 'done');
  });
  it('does not invent departure for a cancelled shipment', () => {
    expect(buildDeliveryChain(delivery('cancelled')).map((step) => step.state)).toEqual(['done', 'skipped', 'done']);
  });
  it('preserves actual departure before cancellation', () => {
    expect(buildDeliveryChain(delivery('cancelled', { departed_at: '2026-10-09T09:00:00Z' }))[1].state).toBe('done');
  });
});
