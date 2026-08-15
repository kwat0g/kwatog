import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { LuArrowLeft, LuCircleCheck, LuMail } from '@/lib/icons';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { customerPortalApi } from '@/api/b2b/customer';
import { supplierPortalApi } from '@/api/b2b/supplier';

type PortalType = 'customer' | 'supplier';

export default function PortalForgotPasswordPage({ portalType }: { portalType: PortalType }) {
  const [email, setEmail] = useState('');
  const [submitted, setSubmitted] = useState(false);
  const [loading, setLoading] = useState(false);
  const label = portalType === 'customer' ? 'Customer' : 'Supplier';
  const loginPath = `/portal/${portalType}/login`;

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setLoading(true);
    try {
      if (portalType === 'customer') await customerPortalApi.forgotPassword(email);
      else await supplierPortalApi.forgotPassword(email);
      setSubmitted(true);
    } catch (error) {
      const body = (error as AxiosError<{ message?: string }>).response?.data;
      toast.error(body?.message ?? 'Could not request a reset link. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <Panel>
      <div className="mb-6">
        <p className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-muted">
          <LuMail size={12} className="text-accent" />
          {label} portal password reset
        </p>
        <h1 className="mt-3 font-display text-2xl tracking-tight text-primary">Forgot your password?</h1>
        <p className="mt-1.5 text-[13px] text-muted">
          Enter your portal email and we&apos;ll send a secure reset link if the account exists.
        </p>
      </div>

      {submitted ? (
        <div role="status" className="rounded-md border border-success/30 bg-success-bg/10 p-5 text-center">
          <LuCircleCheck size={32} className="mx-auto text-success-fg" strokeWidth={1.5} />
          <h2 className="mt-3 font-display text-lg text-primary">Check your inbox</h2>
          <p className="mt-1 text-[13px] text-secondary">If an account exists, you&apos;ll receive a reset link shortly.</p>
          <Link to={loginPath} className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
            <LuArrowLeft size={14} />
            Back to sign in
          </Link>
        </div>
      ) : (
        <form onSubmit={onSubmit} className="flex flex-col gap-3" noValidate>
          <Input
            type="email"
            label="Portal email"
            autoComplete="email"
            autoFocus
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
          <Button type="submit" variant="primary" size="lg" loading={loading} disabled={loading} className="mt-2 w-full">
            Send reset link
          </Button>
          <div className="mt-1 text-center text-xs text-muted">
            <Link to={loginPath} className="inline-flex items-center gap-1 underline-offset-2 transition-colors hover:text-primary hover:underline">
              <LuArrowLeft size={12} />
              Back to sign in
            </Link>
          </div>
        </form>
      )}
    </Panel>
  );
}
