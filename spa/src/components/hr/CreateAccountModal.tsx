import { useEffect } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { Modal, Button, Switch } from '@/components/ui';
import { employeeAccountsApi } from '@/api/hr/employee-accounts';
import type { ApiValidationError } from '@/types';

interface Props {
 isOpen: boolean;
 onClose: () => void;
 employeeId: string;
}

const schema = z.object({
 send_welcome: z.boolean().default(true),
});

type FormValues = z.infer<typeof schema>;

/** U1 — Modal to provision a new system account for an employee. */
export function CreateAccountModal({ isOpen, onClose, employeeId }: Props) {
 const queryClient = useQueryClient();

 const {
 register,
 handleSubmit,
 setError,
 reset,
 formState: { isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { send_welcome: true },
 });

 useEffect(() => {
 if (isOpen) {
 reset({ send_welcome: true });
 }
 }, [isOpen, reset]);

 const mutation = useMutation({
 mutationFn: (values: FormValues) =>
 employeeAccountsApi.provision(employeeId, { send_welcome: values.send_welcome }),
 onSuccess: (r) => {
 toast.success(r.message ?? 'Account created.');
 queryClient.invalidateQueries({ queryKey: ['employee-account', employeeId] });
 queryClient.invalidateQueries({ queryKey: ['employees'] });
 queryClient.invalidateQueries({ queryKey: ['employee-onboarding', employeeId] });
 onClose();
 },
 onError: (error: AxiosError<ApiValidationError>) => {
 if (error.response?.status === 409) {
 toast.error(error.response.data?.message ?? 'Account already exists.');
 return;
 }
 if (error.response?.status === 422 && error.response.data?.errors) {
 Object.entries(error.response.data.errors).forEach(([field, messages]) => {
 setError(field as keyof FormValues, { type: 'server', message: messages[0] });
 });
 toast.error('Please fix the errors below.');
 } else {
 toast.error('Failed to create account.');
 }
 },
 });

 return (
 <Modal isOpen={isOpen} onClose={onClose} size="md" title="Create System Account">
 <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="space-y-4 px-1">
 <p className="text-sm text-secondary">
 The employee record supplies the email when available. Otherwise HR will use the
  configured employee-account domain. New accounts receive the least-privileged Employee role.
 </p>

 <div className="flex items-center justify-between text-sm">
 <span className="text-secondary">Send welcome email with temporary password</span>
 <Switch {...register('send_welcome')} />
 </div>

 <div className="flex justify-end gap-2 pt-3 border-t border-default">
 <Button
 type="button"
 variant="secondary"
 onClick={onClose}
 disabled={mutation.isPending}
 >
 Cancel
 </Button>
 <Button
 type="submit"
 variant="primary"
 disabled={isSubmitting || mutation.isPending}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Creating…' : 'Create Account'}
 </Button>
 </div>
 </form>
 </Modal>
 );
}
