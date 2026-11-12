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
import type { CustomerOrderAdvance, PortalSoDetail } from '@/types/b2b';

const todayManila = () => new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
}).format(new Date());

function proofError(file: File | null): string | null {
  if (!file) return 'Payment proof is required.';
  if (file.size > 10_485_760) return 'Proof must be 10 MiB or smaller.';
  if (!['application/pdf', 'image/jpeg', 'image/png'].includes(file.type)
    || !/\.(pdf|jpe?g|png)$/i.test(file.name)) {
    return 'Choose a PDF, JPEG, or PNG file.';
  }
  return null;
}

export function SubmitOrderAdvanceDialog({
  order, requirement, onClose,
}: {
  order: PortalSoDetail;
  requirement: CustomerOrderAdvance;
  onClose: () => void;
}) {
  const queries = useQueryClient();
  const [file, setFile] = useState<File | null>(null);
  const [fileTouched, setFileTouched] = useState(false);
  const [serverError, setServerError] = useState('');
  const [serverProofError, setServerProofError] = useState('');
  const pending = useRef(false);
  const attempt = useRef<{ signature: string; key: string } | null>(null);
  const schema = z.object({
    sending_bank: z.string().trim().min(1, 'Sending bank is required.').max(100),
    reference_number: z.string().trim().min(1, 'Reference number is required.').max(120),
    payment_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Choose a payment date.')
      .refine((value) => value >= (order.date ?? '') && value <= todayManila(), 'Payment date must fall between the order date and today.'),
  });
  const { register, handleSubmit, formState: { errors, isValid } } = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema), mode: 'onChange',
    defaultValues: { sending_bank: '', reference_number: '', payment_date: todayManila() },
  });

  const mutation = useMutation({
    mutationFn: (form: FormData) => customerPortalApi.submitOrderAdvance(order.id, form),
    onSuccess: async (advance) => {
      pending.current = false;
      await queries.invalidateQueries({ queryKey: ['portal', 'customer', 'order', order.id] });
      await queries.invalidateQueries({ queryKey: ['portal', 'customer', 'orders'] });
      toast.success('Payment evidence submitted. Finance will verify the transfer.');
      onClose();
      return advance;
    },
    onError: (error: Error & { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }) => {
      pending.current = false;
      setServerError(error.response?.data?.message ?? 'Payment evidence could not be confirmed. Reload the order before trying again.');
      const errorsByField = error.response?.data?.errors ?? {};
      setServerProofError(errorsByField.proof_file?.[0] ?? '');
      if (errorsByField.amount?.[0]) setServerError(errorsByField.amount[0]);
      queries.invalidateQueries({ queryKey: ['portal', 'customer', 'order', order.id] });
    },
  });

  const submit = (fields: z.infer<typeof schema>) => {
    if (pending.current || mutation.isPending || !requirement.can_submit || proofError(file)) return;
    const form = new FormData();
    form.append('amount', requirement.required_amount);
    form.append('sending_bank', fields.sending_bank);
    form.append('reference_number', fields.reference_number);
    form.append('payment_date', fields.payment_date);
    form.append('proof_file', file!);
    const signature = JSON.stringify([
      requirement.required_amount, fields.sending_bank, fields.reference_number, fields.payment_date,
      file!.name, file!.size, file!.lastModified,
    ]);
    if (attempt.current?.signature !== signature) attempt.current = { signature, key: crypto.randomUUID() };
    form.append('idempotency_key', attempt.current.key);
    pending.current = true;
    mutation.mutate(form);
  };

  return (
    <Modal isOpen title="Submit order deposit" onClose={() => { if (!pending.current) onClose(); }} size="md" closeOnOverlayClick={false}>
      <form className="space-y-5" onSubmit={handleSubmit(submit)} noValidate>
        <div className="rounded-md border border-default bg-subtle p-3">
          <p className="text-xs font-medium text-muted">Required deposit</p>
          <p className="mt-1 text-lg font-semibold">₱{requirement.required_amount}</p>
          <p className="mt-1 text-xs text-muted">This form records a bank transfer; it does not send money. Confirm the receiving account with Ogami Finance before transferring.</p>
        </div>
        <Input label="Sending bank" required maxLength={100} autoComplete="organization" {...register('sending_bank')} error={errors.sending_bank?.message} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label="Reference number" required maxLength={120} {...register('reference_number')} error={errors.reference_number?.message} />
          <Input label="Payment date" required type="date" min={order.date ?? undefined} max={todayManila()} {...register('payment_date')} error={errors.payment_date?.message} />
        </div>
        <FileInput label="Payment proof" required accept="application/pdf,image/jpeg,image/png" helper="PDF or image, up to 10 MiB. Upload the transfer receipt." error={serverProofError || (fileTouched ? proofError(file) ?? undefined : undefined)} onChange={(event) => { setServerProofError(''); setFile(event.target.files?.[0] ?? null); setFileTouched(true); }} />
        {serverError && <p role="alert" className="text-sm text-danger-fg">{serverError}</p>}
        <ModalFooter>
          <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending} disabled={!isValid || !!proofError(file) || mutation.isPending || !requirement.can_submit}>Submit payment evidence</Button>
        </ModalFooter>
      </form>
    </Modal>
  );
}
