import { useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { customerPortalApi } from '@/api/b2b/customer';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { FileInput } from '@/components/ui/FileInput';
import { Button } from '@/components/ui/Button';
import type { PortalInvoiceDetail } from '@/types/b2b';

const todayManila = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const proofError = (file: File | null) => !file ? 'Payment proof is required.'
  : file.size > 10_485_760 ? 'Proof must be 10 MiB or smaller.'
    : !['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(file.type) || !/\.(pdf|jpe?g|png|webp)$/i.test(file.name)
      ? 'Choose a PDF, JPEG, PNG, or WebP file.' : null;

export function SubmitPaymentDialog({ invoice, onClose }: { invoice: PortalInvoiceDetail; onClose: () => void }) {
  const queries = useQueryClient();
  const [reviewed, setReviewed] = useState(invoice);
  const [file, setFile] = useState<File | null>(null);
  const [fileTouched, setFileTouched] = useState(false);
  const [needsReview, setNeedsReview] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [serverProofError, setServerProofError] = useState('');
  const [serverError, setServerError] = useState('');
  const pending = useRef(false);
  const attempt = useRef<{ signature: string; key: string } | null>(null);
  const fileIdentity = useRef<File | null>(null);
  const schema = z.object({
    sending_bank: z.string().trim().min(1, 'Sending bank is required.').max(100),
    reference_number: z.string().trim().min(1, 'Reference number is required.').max(50),
    payment_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Choose a payment date.')
      .refine((value) => value >= (reviewed.date ?? '') && value <= todayManila(), 'Payment date must fall between invoice date and today.'),
  });
  const { register, handleSubmit, setError, formState: { errors, isValid } } = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema), mode: 'onChange', defaultValues: { sending_bank: '', reference_number: '', payment_date: todayManila() },
  });
  const mutation = useMutation({
    mutationFn: (form: FormData) => customerPortalApi.submitInvoicePayment(invoice.id, form),
    onSuccess: () => {
      pending.current = false;
      queries.invalidateQueries({ queryKey: ['portal', 'customer'] });
      toast.success('Payment submitted. Await Finance verification.');
      onClose();
    },
    onError: (error: Error & { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }) => {
      pending.current = false;
      setServerError(error.response?.data?.message ?? 'Submission could not be confirmed. Review the refreshed invoice before retrying.');
      for (const [field, messages] of Object.entries(error.response?.data?.errors ?? {})) {
        if (field === 'proof_file') setServerProofError(messages[0]);
        else if (field === 'sending_bank' || field === 'reference_number' || field === 'payment_date') setError(field, { message: messages[0] });
        else if (field === 'amount') setServerError(messages[0]);
      }
      setNeedsReview(true);
      queries.invalidateQueries({ queryKey: ['portal', 'customer', 'invoice', invoice.id] });
    },
  });
  const refresh = async () => {
    setRefreshing(true);
    try {
      const fresh = await customerPortalApi.getInvoice(invoice.id);
      queries.setQueryData(['portal', 'customer', 'invoice', invoice.id], fresh);
      setReviewed(fresh);
      setNeedsReview(false);
      setServerError(fresh.can_submit_payment ? '' : fresh.payment_submission_blocked_reason ?? 'Payment already submitted. Check the payment history.');
    } catch { setServerError('Invoice refresh failed. Please retry.'); }
    finally { setRefreshing(false); }
  };
  const submit = (fields: z.infer<typeof schema>) => {
    if (pending.current || mutation.isPending || needsReview || !reviewed.can_submit_payment || proofError(file)) return;
    const signature = JSON.stringify([reviewed.balance, fields]);
    if (attempt.current?.signature !== signature || fileIdentity.current !== file) {
      attempt.current = { signature, key: crypto.randomUUID() };
      fileIdentity.current = file;
    }
    const form = new FormData();
    for (const [key, value] of Object.entries(fields)) form.append(key, value);
    form.append('amount', reviewed.balance);
    form.append('proof_file', file!);
    form.append('idempotency_key', attempt.current!.key);
    pending.current = true;
    mutation.mutate(form);
  };
  return <Modal isOpen title="Submit payment" onClose={() => { if (!pending.current) onClose(); }} size="md" closeOnOverlayClick={false}>
    <form className="space-y-5" onSubmit={handleSubmit(submit)} noValidate>
      <p className="text-sm">{reviewed.invoice_number}</p>
      <Input label="Amount to settle" value={reviewed.balance} readOnly prefix="₱" helper="Full outstanding balance. Finance verifies receipt before updating this invoice." />
      <Input label="Sending bank" required maxLength={100} autoComplete="organization" {...register('sending_bank')} error={errors.sending_bank?.message} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Reference number" required maxLength={50} {...register('reference_number')} error={errors.reference_number?.message} />
        <Input label="Payment date" required type="date" min={reviewed.date ?? undefined} max={todayManila()} {...register('payment_date')} error={errors.payment_date?.message} />
      </div>
      <FileInput label="Payment proof" required accept="application/pdf,image/jpeg,image/png,image/webp" helper="PDF or image, up to 10 MiB. Use the bank transfer receipt." error={serverProofError || (fileTouched ? proofError(file) ?? undefined : undefined)} onChange={(event) => { setServerProofError(''); setFile(event.target.files?.[0] ?? null); setFileTouched(true); }} />
      {serverError && <p role="alert" className="text-sm text-danger-fg">{serverError}</p>}
      {needsReview && <Button variant="secondary" type="button" loading={refreshing} onClick={refresh}>Review refreshed invoice</Button>}
      <ModalFooter>
        <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={onClose}>Cancel</Button>
        <Button type="submit" variant="primary" loading={mutation.isPending} disabled={!isValid || !!proofError(file) || needsReview || refreshing || !reviewed.can_submit_payment || mutation.isPending}>Submit payment evidence</Button>
      </ModalFooter>
    </form>
  </Modal>;
}
