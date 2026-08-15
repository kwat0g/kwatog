import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import { LuKeyRound, LuCircleCheck } from '@/lib/icons';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { PasswordStrength } from '@/components/ui/PasswordStrength';
import { authApi } from '@/api/auth';
import { useQuery } from '@tanstack/react-query';
import { landingApi } from '@/api/landing';
import {
  PasswordMatchHint,
  PasswordRequirements,
} from '@/components/ui/PasswordRequirements';
import { isStrongPassword, passwordMinimumLength } from '@/components/ui/passwordValidation';

const schema = z
  .object({
    password: z.string().min(1, 'Password is required'),
    password_confirmation: z.string().min(1, 'Confirm your password'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match',
    path: ['password_confirmation'],
  });

type ResetPasswordForm = z.infer<typeof schema>;

export default function ResetPasswordPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token');
  const [done, setDone] = useState(false);
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const { data: policy } = useQuery({
    queryKey: ['auth', 'password-policy'],
    queryFn: authApi.passwordPolicy,
    staleTime: 300_000,
  });

  useEffect(() => {
    if (!token) {
      navigate('/login', { replace: true });
    }
  }, [token, navigate]);

  const {
    register,
    handleSubmit,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<ResetPasswordForm>({
    resolver: zodResolver(schema),
  });

  const passwordValue = watch('password', '');
  const confirmationValue = watch('password_confirmation', '');
  const minimumLength = passwordMinimumLength(policy);
  const passwordIsStrong = isStrongPassword(passwordValue, policy);

  const onSubmit = async (data: ResetPasswordForm) => {
    if (!token) return;
    if (!isStrongPassword(data.password, policy)) {
      setError('password', { type: 'validate', message: 'Complete all password requirements.' });
      return;
    }
    try {
      await authApi.resetPassword({
        token,
        password: data.password,
        password_confirmation: data.password_confirmation,
      });
      setDone(true);
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const body = axe.response?.data;
      if (axe.response?.status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof ResetPasswordForm, {
            type: 'server',
            message: msgs[0] ?? 'Invalid value.',
          });
        });
      } else {
        setError('root', {
          type: 'server',
          message: body?.message ?? 'Could not reset password. Please try again.',
        });
      }
    }
  };

  if (!token) return null;

  return (
    <Panel>
      <div className="mb-6">
        <p className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-muted">
          <LuKeyRound size={12} className="text-accent" />
          New password
        </p>
        <h1 className="mt-3 font-display text-2xl tracking-tight text-primary">
          Choose a new password
        </h1>
        <p className="mt-1.5 text-[13px] text-muted">
          Make it strong — you&apos;ll use it to sign in to {contact?.legal_name ?? 'your ERP'}.
        </p>
      </div>

      {done ? (
        <div
          role="status"
          className="rounded-md border border-success/30 bg-success-bg/10 p-5 text-center"
        >
          <LuCircleCheck size={32} className="mx-auto text-success-fg" strokeWidth={1.5} />
          <h2 className="mt-3 font-display text-lg text-primary">Password updated</h2>
          <p className="mt-1 text-[13px] text-secondary">
            You can now sign in with your new password.
          </p>
          <Link
            to="/login"
            className="mt-4 inline-block text-sm font-medium text-accent hover:underline"
          >
            Go to sign in
          </Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3" noValidate>
          <FormErrorSummary errors={errors} />
          <Input
            type="password"
            label="New password"
            autoComplete="new-password"
            minLength={minimumLength}
            {...register('password')}
            error={errors.password?.message}
          />
          <PasswordStrength password={passwordValue} minimumLength={minimumLength} />
          <PasswordRequirements password={passwordValue} policy={policy} className="mt-1" />
          <Input
            type="password"
            label="Confirm new password"
            autoComplete="new-password"
            minLength={minimumLength}
            {...register('password_confirmation')}
            error={errors.password_confirmation?.message}
          />
          <PasswordMatchHint password={passwordValue} confirmation={confirmationValue} />
          <Button
            type="submit"
            variant="primary"
            size="lg"
            loading={isSubmitting}
            disabled={isSubmitting || !token || !passwordIsStrong || passwordValue !== confirmationValue}
            className="mt-2 w-full"
          >
            Reset password
          </Button>
        </form>
      )}
    </Panel>
  );
}
