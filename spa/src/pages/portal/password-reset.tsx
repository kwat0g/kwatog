import { useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { LuCircleCheck, LuKeyRound } from '@/lib/icons';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { customerPortalApi } from '@/api/b2b/customer';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { authApi } from '@/api/auth';
import { PasswordStrength } from '@/components/ui/PasswordStrength';
import {
  PasswordMatchHint,
  PasswordRequirements,
} from '@/components/ui/PasswordRequirements';
import { isStrongPassword, passwordMinimumLength } from '@/components/ui/passwordValidation';

export default function PortalPasswordResetPage() {
  const [params] = useSearchParams();
  const portalType = params.get('type') === 'supplier' ? 'supplier' : 'customer';
  const token = params.get('token') ?? '';
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [done, setDone] = useState(false);
  const [loading, setLoading] = useState(false);
  const loginPath = `/portal/${portalType}/login`;
  const { data: policy } = useQuery({ queryKey: ['auth', 'password-policy'], queryFn: authApi.passwordPolicy, staleTime: 300_000 });
  const minimumLength = passwordMinimumLength(policy);
  const validPassword = isStrongPassword(password, policy);

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!validPassword) {
      toast.error('Complete all password requirements.');
      return;
    }
    if (password !== confirmation) {
      toast.error('Passwords do not match.');
      return;
    }
    setLoading(true);
    try {
      if (portalType === 'customer') await customerPortalApi.resetPassword(token, password, confirmation);
      else await supplierPortalApi.resetPassword(token, password, confirmation);
      setDone(true);
    } catch (error) {
      const body = (error as AxiosError<{ message?: string }>).response?.data;
      toast.error(body?.message ?? 'Could not reset your password. Request a new link and try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <Panel>
      <div className="mb-6">
        <p className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-muted">
          <LuKeyRound size={12} className="text-accent" />
          Portal password reset
        </p>
        <h1 className="mt-3 font-display text-2xl tracking-tight text-primary">Choose a new password</h1>
        <p className="mt-1.5 text-[13px] text-muted">Use at least 8 characters, including a number and symbol.</p>
      </div>

      <form onSubmit={onSubmit} className="flex flex-col gap-3" noValidate>
        {done ? (
          <div role="status" className="rounded-md border border-success/30 bg-success-bg/10 p-5 text-center">
            <LuCircleCheck size={32} className="mx-auto text-success-fg" strokeWidth={1.5} />
            <h2 className="mt-3 font-display text-lg text-primary">Password updated</h2>
            <p className="mt-1 text-[13px] text-secondary">Your portal password was updated successfully.</p>
            <Link to={loginPath} className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
              Go to sign in
            </Link>
          </div>
        ) : (
          <>
           <Input type="password" label="New password" autoComplete="new-password" required minLength={minimumLength} value={password} onChange={(event) => setPassword(event.target.value)} />
           <PasswordStrength password={password} minimumLength={minimumLength} />
           <PasswordRequirements password={password} policy={policy} />
           <Input type="password" label="Confirm password" autoComplete="new-password" required minLength={minimumLength} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
           <PasswordMatchHint password={password} confirmation={confirmation} />
           <Button type="submit" variant="primary" size="lg" loading={loading} disabled={loading || !token || !validPassword || password !== confirmation} className="mt-2 w-full">
              Update password
            </Button>
          </>
        )}
      </form>
    </Panel>
  );
}
