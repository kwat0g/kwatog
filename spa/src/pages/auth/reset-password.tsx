import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import { KeyRound, CheckCircle } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { PasswordStrength } from '@/components/ui/PasswordStrength';
import { authApi } from '@/api/auth';

const schema = z
  .object({
    password: z
      .string()
      .min(8, 'Password must be at least 8 characters')
      .regex(/[A-Z]/, 'Password must contain an uppercase letter')
      .regex(/[0-9]/, 'Password must contain a digit')
      .regex(/[^A-Za-z0-9]/, 'Password must contain a special character'),
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
    formState: { errors, isSubmitSuccessful, isSubmitting },
  } = useForm<ResetPasswordForm>({
    resolver: zodResolver(schema),
  });

  const passwordValue = watch('password', '');

  const onSubmit = async (data: ResetPasswordForm) => {
    if (!token) return;
    try {
      await authApi.resetPassword({
        token,
        password: data.password,
        password_confirmation: data.password_confirmation,
      });
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
        <p className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-landing-muted">
          <KeyRound size={12} className="text-landing-accent" />
          New password
        </p>
        <h1 className="mt-3 font-display text-3xl font-bold tracking-tight text-landing-text">
          Choose a new password
        </h1>
        <p className="mt-1.5 text-[13px] text-landing-muted">
          Make it strong — you&apos;ll use it to sign in to the Ogami ERP.
        </p>
      </div>

      {isSubmitSuccessful && !errors.root ? (
        <div
          role="status"
          className="rounded-xl border border-success/30 bg-success/10 p-5 text-center"
        >
          <CheckCircle size={32} className="mx-auto text-success" strokeWidth={1.5} />
          <h2 className="mt-3 font-display text-lg font-semibold text-landing-text">
            Password updated
          </h2>
          <p className="mt-1 text-[13px] text-landing-text-secondary">
            You can now sign in with your new password.
          </p>
          <Link
            to="/login"
            className="mt-4 inline-block text-sm font-medium text-landing-accent hover:underline"
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
            {...register('password')}
            error={errors.password?.message}
          />
          <PasswordStrength password={passwordValue} />
          <Input
            type="password"
            label="Confirm new password"
            autoComplete="new-password"
            {...register('password_confirmation')}
            error={errors.password_confirmation?.message}
          />
          <Button
            type="submit"
            variant="primary"
            size="lg"
            loading={isSubmitting}
            disabled={isSubmitting}
            className="mt-2 w-full"
          >
            Reset password
          </Button>
        </form>
      )}
    </Panel>
  );
}
