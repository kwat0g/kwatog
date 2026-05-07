import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { Modal, Button, Input, Select, Switch } from '@/components/ui';
import { client } from '@/api/client';
import { employeeAccountsApi } from '@/api/hr/employee-accounts';
import type { ApiValidationError } from '@/types';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  employeeId: string;
  suggestedEmail?: string;
}

const schema = z.object({
  email: z.string().email('Invalid email').max(255).optional().or(z.literal('')),
  role_id: z.string().optional().or(z.literal('')),
  send_welcome: z.boolean().default(true),
});

type FormValues = z.infer<typeof schema>;

interface RoleOption {
  id: string;
  name: string;
  slug: string;
}

/** U1 — Modal to provision a new system account for an employee. */
export function CreateAccountModal({ isOpen, onClose, employeeId, suggestedEmail }: Props) {
  const queryClient = useQueryClient();

  // Pull roles for the role dropdown. Best-effort: if the user lacks
  // admin.roles.manage permission, we still let the backend pick a default.
  const rolesQuery = useQuery<{ data: RoleOption[] }>({
    queryKey: ['admin-roles-list'],
    queryFn: () => client.get('/admin/roles').then((r) => r.data),
    enabled: isOpen,
    staleTime: 60_000,
  });
  const roles = rolesQuery.data?.data ?? [];

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', role_id: '', send_welcome: true },
  });

  useEffect(() => {
    if (isOpen) {
      reset({ email: suggestedEmail ?? '', role_id: '', send_welcome: true });
    }
  }, [isOpen, suggestedEmail, reset]);

  const mutation = useMutation({
    mutationFn: (values: FormValues) =>
      employeeAccountsApi.provision(employeeId, {
        email: values.email || undefined,
        role_id: values.role_id || undefined,
        send_welcome: values.send_welcome,
      }),
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
        <Input
          label="Email"
          placeholder={suggestedEmail || 'auto-generated'}
          {...register('email')}
          error={errors.email?.message as string | undefined}
        />

        <Select
          label="Role"
          {...register('role_id')}
          error={errors.role_id?.message as string | undefined}
        >
          <option value="">Default (Employee)</option>
          {roles.map((r) => (
            <option key={r.id} value={r.id}>
              {r.name}
            </option>
          ))}
        </Select>

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
