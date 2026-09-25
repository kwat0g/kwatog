import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { returnCasesApi } from '@/api/returnCases';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';

const schema = z.object({ request_key: z.string().uuid(), message: z.string().trim().max(2000) });
type Values = z.infer<typeof schema>;
function readPending(key: string): Values | null {
  try {
    const result = schema.safeParse(JSON.parse(localStorage.getItem(key) ?? 'null'));
    return result.success ? result.data : null;
  } catch { return null; }
}

export function NotArrivedPanel({ deliveryId, onClose }: { deliveryId: string; onClose: () => void }) {
  const key = `ogami:delivery-trace:${deliveryId}`;
  const [pending, setPending] = useState<Values | null>(() => readPending(key));
  const [failure, setFailure] = useState('');
  const navigate = useNavigate();
  const qc = useQueryClient();
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: pending ?? { request_key: crypto.randomUUID(), message: '' } });
  function remember(value: Values | null) {
    setPending(value);
    try { if (value) localStorage.setItem(key, JSON.stringify(value)); else localStorage.removeItem(key); } catch { /* Retain exact retry in the open page. */ }
  }
  const mutation = useMutation({
    mutationFn: (value: Values) => returnCasesApi.reportNotArrived(deliveryId, value),
    onSuccess: async (record) => {
      remember(null);
      await qc.invalidateQueries({ queryKey: ['portal', 'customer'] });
      toast.success('Shipment report sent. You can follow updates here.');
      navigate(`/portal/customer/problems/${record.id}`);
    },
    onError: (error) => {
      if (isAxiosError(error) && error.response && error.response.status < 500) {
        remember(null);
        const message = error.response.data?.errors?.message?.[0];
        if (message) form.setError('message', { message });
        setFailure(error.response.data?.message ?? 'Could not send this report. Please try again.');
      } else setFailure('We could not confirm the save. Retry the saved report to recover it without creating a duplicate.');
      toast.error('Could not confirm the shipment report.');
    },
  });
  return <Panel title="Shipment has not arrived">
    <form className="space-y-4" onSubmit={form.handleSubmit((values) => { remember(values); setFailure(''); mutation.mutate(values); })}>
      <p className="text-sm">Our team will check with Dispatch and reply in your report. You do not need to enter item quantities.</p>
      <Textarea label="Details (optional)" rows={3} maxLength={2000} disabled={Boolean(pending) || mutation.isPending} {...form.register('message')} error={form.formState.errors.message?.message} />
      {failure && <p role="alert" className="text-sm text-danger-fg">{failure}</p>}
      <div className="flex flex-wrap justify-end gap-2">
        <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={onClose}>Cancel</Button>
        {pending ? <Button type="button" variant="primary" loading={mutation.isPending} onClick={() => mutation.mutate(pending)}>Retry saved report</Button>
          : <Button type="submit" variant="primary" loading={mutation.isPending}>Send shipment report</Button>}
      </div>
    </form>
  </Panel>;
}
