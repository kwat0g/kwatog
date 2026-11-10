import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { customerPortalApi } from '@/api/b2b/customer';
import { SubmitOrderAdvanceDialog } from './SubmitOrderAdvanceDialog';

vi.mock('@/api/b2b/customer', () => ({ customerPortalApi: { submitOrderAdvance: vi.fn() } }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('requires bank evidence and submits the configured amount with an idempotency key', async () => {
  vi.stubGlobal('crypto', { randomUUID: () => 'a2a43a3e-0f2c-4ce8-b7ef-20b5d1111111' });
  vi.mocked(customerPortalApi.submitOrderAdvance).mockResolvedValue({
    id: 'advance-1', status: 'pending', required_amount: '250.00', submitted_amount: '250.00',
  } as never);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={queryClient}><SubmitOrderAdvanceDialog
    order={{ id: 'so-1', so_number: 'SO-1', date: '2026-11-01', total_amount: '1000.00', status: 'confirmed', created_at: '2026-11-01' }}
    requirement={{ id: 'advance-1', required_amount: '250.00', percentage: '25.00', status: 'configured', can_submit: true }}
    onClose={vi.fn()}
  /></QueryClientProvider>);

  fireEvent.change(screen.getByLabelText(/Sending bank/), { target: { value: 'BDO' } });
  fireEvent.change(screen.getByLabelText(/Reference number/), { target: { value: 'BANK-REF-001' } });
  fireEvent.change(screen.getByLabelText(/Payment date/), { target: { value: '2026-11-03' } });
  fireEvent.change(screen.getByLabelText(/Payment proof/), { target: { files: [new File(['receipt'], 'receipt.pdf', { type: 'application/pdf' })] } });
  fireEvent.submit(screen.getByRole('dialog').querySelector('form')!);

  await waitFor(() => expect(customerPortalApi.submitOrderAdvance).toHaveBeenCalledOnce());
  const [orderId, form] = vi.mocked(customerPortalApi.submitOrderAdvance).mock.calls[0];
  expect(orderId).toBe('so-1');
  expect(form.get('amount')).toBe('250.00');
  expect(form.get('sending_bank')).toBe('BDO');
  expect(form.get('reference_number')).toBe('BANK-REF-001');
  expect(form.get('idempotency_key')).toBe('a2a43a3e-0f2c-4ce8-b7ef-20b5d1111111');
  expect(form.get('proof_file')).toBeInstanceOf(File);
  queryClient.clear();
});
