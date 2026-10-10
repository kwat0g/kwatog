import { expect, it } from 'vitest';
import { buildO2cChain } from './o2c';
const invoice = { id: 'invoice-1', invoice_number: 'INV-1', status: 'finalized' };
it('pending evidence never implies posted collection', () => {
  const step = buildO2cChain({ invoices: [invoice], paymentStage: 'pending' }).find((item) => item.key === 'payment');
  expect(step?.state).toBe('active');
  expect(step?.description).toContain('verify actual receipt of funds');
});
it('draft waits for finalization while other callers retain behavior', () => {
  expect(buildO2cChain({ invoices: [{ ...invoice, status: 'draft' }], paymentStage: 'awaiting_invoice' }).at(-1)?.state).toBe('pending');
  expect(buildO2cChain({ invoices: [invoice] }).at(-1)?.description).toContain('Record a collection');
  expect(buildO2cChain({ invoices: [{ ...invoice, status: 'paid' }], paymentStage: 'verified' }).at(-1)?.state).toBe('done');
});
