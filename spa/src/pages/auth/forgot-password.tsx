import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link } from 'react-router-dom';
import { useState } from 'react';
import { ArrowLeft, Mail, CheckCircle } from 'lucide-react';
import { AxiosError } from 'axios';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { authApi } from '@/api/auth';

const schema = z.object({
  email: z.string().min(1, 'Email is required').email('Invalid email'),
});

type ForgotPasswordForm = z.infer<typeof schema>;

export default function ForgotPasswordPage() {
  const [submitted, setSubmitted] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ForgotPasswordForm>({
    resolver: zodResolver(schema),
  });

  const onSubmit = async (data: ForgotPasswordForm) => {
    try {
      await authApi.requestPasswordReset(data.email);
      setSubmitted(true);
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const body = axe.response?.data;
      if (axe.response?.status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof ForgotPasswordForm, {
            type: 'server',
            message: msgs[0] ?? 'Invalid value.',
          });
        });
      } else {
        setError('root', {
          type: 'server',
          message: body?.message ?? 'Could not send reset link. Please try again.',
        });
      }
    }
  };

  return (
    <Panel>
      <div className="mb-6">
        <p className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-landing-muted">
          <Mail size={12} className="text-landing-accent" />
          Reset password
        </p>
        <h1 className="mt-3 font-display text-3xl font-bold tracking-tight text-landing-text">
          Forgot your password?
        </h1>
        <p className="mt-1.5 text-[13px] text-landing-muted">
          Enter your work email and we&apos;ll send you a secure reset link.
        </p>
      </div>

      {submitted ? (
        <div
          role="status"
          className="rounded-xl border border-success/30 bg-success/10 p-5 text-center"
        >
          <CheckCircle size={32} className="mx-auto text-success" strokeWidth={1.5} />
          <h2 className="mt-3 font-display text-lg font-semibold text-landing-text">
            Check your inbox
          </h2>
          <p className="mt-1 text-[13px] text-landing-text-secondary">
            If an account exists for that email, you&apos;ll receive a reset link shortly.
          </p>
          <Link
            to="/login"
            className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-landing-accent hover:underline"
          >
            <ArrowLeft size={14} />
            Back to sign in
          </Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3" noValidate>
          <FormErrorSummary errors={errors} />
          <Input
            type="email"
            label="Email"
            autoComplete="email"
            autoFocus
            {...register('email')}
            error={errors.email?.message}
          />
          <Button
            type="submit"
            variant="primary"
            size="lg"
            loading={isSubmitting}
            disabled={isSubmitting}
            className="mt-2 w-full"
          >
            Send reset link
          </Button>
          <div className="mt-1 text-center text-xs text-landing-muted">
            <Link
              to="/login"
              className="inline-flex items-center gap-1 underline-offset-2 transition-colors hover:text-landing-text hover:underline"
            >
              <ArrowLeft size={12} />
              Back to sign in
            </Link>
          </div>
        </form>
      )}
    </Panel>
  );
}
