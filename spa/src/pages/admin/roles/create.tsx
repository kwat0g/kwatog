import { useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { rolesApi } from '@/api/admin/roles';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';

/**
 * Series R — Task R1.
 *
 * Two-mode form:
 *   - "Start from scratch" → POST /admin/roles, no permissions copied.
 *   - "Clone from existing" → POST /admin/roles/{source}/clone, copies all
 *     permissions of the source role into the new role.
 *
 * Submit always navigates to the permission editor for the freshly created
 * role so the admin can immediately tailor the permission set.
 */
const schema = z
  .object({
    name:        z.string().min(1, 'Name is required.').max(50),
    slug:        z.string().min(1, 'Slug is required.').max(50)
                  .regex(/^[a-z0-9_-]+$/, 'Use lowercase letters, numbers, dashes or underscores.'),
    description: z.string().max(500).optional().or(z.literal('')),
    clone:       z.boolean().default(false),
    source_role_id: z.string().optional().or(z.literal('')),
  })
  .refine((d) => !d.clone || (d.source_role_id && d.source_role_id !== ''), {
    message: 'Pick a source role to clone from.',
    path: ['source_role_id'],
  });

type FormValues = z.infer<typeof schema>;

export default function CreateRolePage() {
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();

  // Series R/R1 — when arriving from the index page's "Clone" action, the
  // referrer passes the source role's hash_id via router state. Pre-fill the
  // clone toggle and source dropdown so the admin lands on a ready-to-submit
  // form instead of having to flip the switch and re-pick the role.
  const cloneFromState =
    typeof location.state === 'object' && location.state !== null && 'cloneFrom' in location.state
      ? String((location.state as { cloneFrom?: unknown }).cloneFrom ?? '')
      : '';

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      clone: !!cloneFromState,
      name: '',
      slug: '',
      description: '',
      source_role_id: cloneFromState,
    },
  });

  const cloneMode = watch('clone');
  const nameValue = watch('name');

  // Auto-suggest slug from the name field while the user hasn't typed one.
  useEffect(() => {
    const slug = (nameValue ?? '')
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '');
    if (slug) setValue('slug', slug, { shouldValidate: false });
  }, [nameValue, setValue]);

  // Source role options when cloning.
  const sources = useQuery({
    queryKey: ['admin', 'roles', 'all-for-clone'],
    queryFn: () => rolesApi.list({ per_page: 100, sort: 'name', direction: 'asc' }),
    staleTime: 60_000,
    enabled: cloneMode,
  });

  const submit = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload = {
        name: v.name,
        slug: v.slug,
        description: v.description?.trim() ? v.description : null,
      };
      if (v.clone && v.source_role_id) {
        return rolesApi.clone(v.source_role_id, payload);
      }
      return rolesApi.create(payload);
    },
    onSuccess: (role) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] });
      toast.success(`Role “${role.name}” created. Configure its permissions next.`);
      navigate(`/admin/roles/${role.id}/permissions`);
    },
    onError: (error: AxiosError<ApiValidationError>) => {
      if (error.response?.status === 422 && error.response.data.errors) {
        for (const [field, messages] of Object.entries(error.response.data.errors)) {
          setError(field as keyof FormValues, { type: 'server', message: messages[0] });
        }
        toast.error('Please fix the errors below.');
        return;
      }
      // Other errors handled by axios interceptor.
    },
  });

  return (
    <div>
      <PageHeader
        title="New role"
        subtitle="Define a custom role users can be assigned to. You configure its permissions on the next screen."
        backTo="/admin/roles"
        backLabel="Roles"
        breadcrumbs={[
          { label: 'Admin', href: '/admin' },
          { label: 'Roles', href: '/admin/roles' },
          { label: 'New Role' },
        ]}
      />

      <form
        onSubmit={handleSubmit((v) => submit.mutate(v))}
        className="max-w-2xl px-5 py-6"
      >
        {/* ─── Basic info ────────────────────────────────── */}
        <fieldset className="mb-8">
          <legend className="text-2xs uppercase tracking-wider text-muted font-medium mb-4">
            Basic information
          </legend>
          <div className="grid grid-cols-1 gap-3">
            <Input
              label="Role name"
              placeholder="e.g. Line Supervisor"
              {...register('name')}
              error={errors.name?.message}
              required
            />
            <Input
              label="Slug"
              placeholder="line_supervisor"
              {...register('slug')}
              error={errors.slug?.message}
              helper="Lowercase identifier used in middleware and URLs. Auto-suggested from the name."
              className="font-mono"
              required
            />
            <Textarea
              label="Description"
              placeholder="Approves output recording and machine status changes on the day shift."
              {...register('description')}
              error={errors.description?.message}
              rows={3}
            />
          </div>
        </fieldset>

        {/* ─── Clone source ──────────────────────────────── */}
        <fieldset className="mb-8">
          <legend className="text-2xs uppercase tracking-wider text-muted font-medium mb-4">
            Starting permissions
          </legend>
          <div className="mb-3">
            <Switch
              checked={cloneMode}
              onChange={(e) => setValue('clone', e.target.checked, { shouldValidate: true })}
              label="Clone from an existing role"
              description="Copies that role's permission set as the starting point. You can adjust on the next screen."
              aria-label="Clone from existing role"
            />
          </div>

          {cloneMode && (
            <Select
              label="Source role"
              {...register('source_role_id')}
              error={errors.source_role_id?.message}
              required
            >
              <option value="">Select a role…</option>
              {(sources.data?.data ?? []).map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name} {r.is_system ? '(System)' : ''}
                </option>
              ))}
            </Select>
          )}
        </fieldset>

        {/* ─── Actions ───────────────────────────────────── */}
        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/admin/roles')}
            disabled={isSubmitting || submit.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || submit.isPending}
            loading={submit.isPending}
          >
            {submit.isPending ? 'Creating…' : 'Create role'}
          </Button>
        </div>
      </form>
    </div>
  );
}
