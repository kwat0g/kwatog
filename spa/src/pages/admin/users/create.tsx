import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { Button, Input, Modal, Panel, Select, Switch } from '@/components/ui';
import { PageHeader } from '@/components/layout/PageHeader';
import { adminUsersApi } from '@/api/admin/users';
import { client } from '@/api/client';
import type { ApiValidationError } from '@/types';

interface RoleOption { id: string; name: string }
interface RolesResponse { data: RoleOption[] }

const schema = z.object({
  name: z.string().min(1, 'Name is required').max(120),
  email: z.string().email('Invalid email').max(255),
  role_id: z.string().min(1, 'Role is required'),
  send_welcome: z.boolean().default(true),
});

type FormValues = z.infer<typeof schema>;

/** U2 — Admin > Create User (standalone, no employee link). */
export default function AdminCreateUserPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [tempPasswordModal, setTempPasswordModal] = useState<string | null>(null);

  const rolesQuery = useQuery<RolesResponse>({
    queryKey: ['admin-roles-list'],
    queryFn: () => client.get('/admin/roles').then((r) => r.data),
    staleTime: 60_000,
  });

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', email: '', role_id: '', send_welcome: true },
  });

  const mutation = useMutation({
    mutationFn: (v: FormValues) => adminUsersApi.create(v),
    onSuccess: (r) => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] });
      toast.success(r.message ?? 'User created.');
      if (r.data.temp_password) {
        setTempPasswordModal(r.data.temp_password);
      } else {
        navigate(`/admin/users/${r.data.id}`);
      }
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data?.errors) {
        Object.entries(err.response.data.errors).forEach(([field, messages]) => {
          setError(field as keyof FormValues, {
            type: 'server',
            message: (messages as string[])[0],
          });
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error('Failed to create user.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="Create User" backTo="/admin/users-roles" backLabel="Users & Roles" breadcrumbs={[{ label: 'Admin', href: '/admin' }, { label: 'Users & Roles', href: '/admin/users-roles' }, { label: 'Users', href: '/admin/users' }, { label: 'New User' }]} />

      <form
        onSubmit={handleSubmit((v) => mutation.mutate(v))}
        className="max-w-2xl mx-auto px-5 py-6"
      >
        <Panel>
          <fieldset className="space-y-4">
            <Input
              label="Full Name"
              {...register('name')}
              error={errors.name?.message}
              required
            />
            <Input
              label="Email"
              type="email"
              {...register('email')}
              error={errors.email?.message}
              required
            />
            <Select
              label="Role"
              {...register('role_id')}
              error={errors.role_id?.message}
              required
            >
              <option value="">Select a role</option>
              {(rolesQuery.data?.data ?? []).map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </Select>
            <div className="flex items-center justify-between text-sm">
              <span className="text-secondary">Send welcome email with temporary password</span>
              <Switch {...register('send_welcome')} />
            </div>
          </fieldset>
        </Panel>

        <div className="flex justify-end gap-2 pt-4 border-t border-default mt-4">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/admin/users')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Creating…' : 'Create User'}
          </Button>
        </div>
      </form>

      <Modal
        isOpen={!!tempPasswordModal}
        onClose={() => {
          setTempPasswordModal(null);
          navigate('/admin/users');
        }}
        title="Account Created"
        size="sm"
      >
        <div className="space-y-3 px-1">
          <p className="text-sm">
            Account created successfully. Copy this temporary password — it is shown
            only once. The user must change it on first login.
          </p>
          <code className="block bg-elevated rounded-md p-3 font-mono tabular-nums text-md select-all">
            {tempPasswordModal}
          </code>
          <div className="flex justify-end pt-2 border-t border-default">
            <Button
              variant="primary"
              onClick={() => {
                setTempPasswordModal(null);
                navigate('/admin/users');
              }}
            >
              Done
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
