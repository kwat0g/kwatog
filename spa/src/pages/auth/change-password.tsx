import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Eye, EyeOff, Check, X } from 'lucide-react';
import { authApi } from '@/api/auth';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { PasswordStrength } from '@/components/ui/PasswordStrength';
import { cn } from '@/lib/cn';

const schema = z
  .object({
    current_password: z.string().min(1, 'Current password is required'),
    new_password: z
      .string()
      .min(8, 'Password must be at least 8 characters')
      .regex(/[A-Z]/, 'Password must contain an uppercase letter')
      .regex(/[0-9]/, 'Password must contain a digit')
      .regex(/[^A-Za-z0-9]/, 'Password must contain a special character'),
    new_password_confirmation: z.string().min(1, 'Confirm your new password'),
  })
  .refine((data) => data.new_password === data.new_password_confirmation, {
    message: 'Passwords do not match',
    path: ['new_password_confirmation'],
  });

type ChangePasswordForm = z.infer<typeof schema>;

const POLICY = [
  { test: (v: string) => v.length >= 8, label: 'At least 8 characters' },
  { test: (v: string) => /[A-Z]/.test(v), label: 'An uppercase letter' },
  { test: (v: string) => /[0-9]/.test(v), label: 'A digit' },
  { test: (v: string) => /[^A-Za-z0-9]/.test(v), label: 'A special character' },
];

export default function ChangePasswordPage() {
  const navigate = useNavigate();
  const refresh = useAuthStore((s) => s.refresh);
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<ChangePasswordForm>({
    resolver: zodResolver(schema),
  });

  const newPassword = watch('new_password', '');

  const onSubmit = async (data: ChangePasswordForm) => {
    try {
      await authApi.changePassword({
        current_password: data.current_password,
        new_password: data.new_password,
        new_password_confirmation: data.new_password_confirmation,
      });
      toast.success('Password updated.');
      await refresh();
      navigate('/dashboard', { replace: true });
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = axe.response?.status;
      const body = axe.response?.data;

      if (status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof ChangePasswordForm, {
            type: 'server',
            message: msgs[0] ?? 'Invalid value.',
          });
        });
      } else {
        setError('root', {
          type: 'server',
          message: body?.message ?? 'Could not update password.',
        });
      }
    }
  };

  const PasswordToggle = ({
    shown,
    onToggle,
    label,
  }: {
    shown: boolean;
    onToggle: () => void;
    label: string;
  }) => (
    <button
      type="button"
      tabIndex={-1}
      onClick={onToggle}
      aria-label={shown ? `Hide ${label}` : `Show ${label}`}
      className="flex h-full items-center justify-center px-2 text-landing-muted transition-colors hover:text-landing-text"
    >
      {shown ? <EyeOff size={15} /> : <Eye size={15} />}
    </button>
  );

  return (
    <Panel title="Change password">
      <p className="mb-4 text-sm text-muted">
        For your security, please choose a new password before continuing.
      </p>

      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3">
        <FormErrorSummary errors={errors} />
        <Input
          type={showCurrent ? 'text' : 'password'}
          label="Current password"
          autoComplete="current-password"
          {...register('current_password')}
          error={errors.current_password?.message}
          suffix={
            <PasswordToggle
              shown={showCurrent}
              onToggle={() => setShowCurrent((v) => !v)}
              label="current password"
            />
          }
        />
        <Input
          type={showNew ? 'text' : 'password'}
          label="New password"
          autoComplete="new-password"
          {...register('new_password')}
          error={errors.new_password?.message}
          suffix={
            <PasswordToggle
              shown={showNew}
              onToggle={() => setShowNew((v) => !v)}
              label="new password"
            />
          }
        />
        <PasswordStrength password={newPassword} />
        <Input
          type={showConfirm ? 'text' : 'password'}
          label="Confirm new password"
          autoComplete="new-password"
          {...register('new_password_confirmation')}
          error={errors.new_password_confirmation?.message}
          suffix={
            <PasswordToggle
              shown={showConfirm}
              onToggle={() => setShowConfirm((v) => !v)}
              label="confirm password"
            />
          }
        />

        <ul className="mt-1 space-y-0.5 text-xs">
          {POLICY.map((p) => {
            const passed = p.test(newPassword);
            return (
              <li
                key={p.label}
                className={cn(
                  'flex items-center gap-1.5 transition-colors',
                  passed ? 'text-success' : 'text-muted',
                )}
              >
                {passed ? <Check size={12} /> : <X size={12} />}
                {p.label}
              </li>
            );
          })}
        </ul>

        <Button
          type="submit"
          variant="primary"
          loading={isSubmitting}
          disabled={isSubmitting}
          className="mt-2 w-full"
        >
          {isSubmitting ? 'Updating…' : 'Update password'}
        </Button>
      </form>
    </Panel>
  );
}
