import { useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { PasswordStrength } from '@/components/ui/PasswordStrength';
import {
  PasswordMatchHint,
  PasswordRequirements,
} from '@/components/ui/PasswordRequirements';
import { isStrongPassword, passwordMinimumLength } from '@/components/ui/passwordValidation';
import { authApi } from '@/api/auth';
import { customerPortalApi } from '@/api/b2b/customer';
import { supplierPortalApi } from '@/api/b2b/supplier';

export default function PortalChangePasswordPage() {
  const { type = 'customer' } = useParams<{ type: 'customer' | 'supplier' }>();
  const navigate = useNavigate();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const { data: policy } = useQuery({ queryKey: ['auth', 'password-policy'], queryFn: authApi.passwordPolicy, staleTime: 300_000 });
  const minimumLength = passwordMinimumLength(policy);
  const valid = isStrongPassword(password, policy) && password === confirmation && current.length > 0;

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!valid) {
      toast.error(password !== confirmation ? 'Passwords do not match.' : 'Complete all password requirements.');
      return;
    }
    setLoading(true);
    try {
      const payload = { current_password: current, new_password: password, new_password_confirmation: confirmation };
      if (type === 'supplier') await supplierPortalApi.changePassword(payload);
      else await customerPortalApi.changePassword(payload);
      toast.success('Password updated. Please sign in again.');
      if (type === 'supplier') await supplierPortalApi.logout();
      else await customerPortalApi.logout();
      navigate(`/portal/${type}/login`, { replace: true });
    } catch (error) {
      const body = (error as AxiosError<{ message?: string }>).response?.data;
      toast.error(body?.message ?? 'Could not update your password.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <Panel title="Set your portal password">
      <p className="mb-4 text-sm text-muted">Your temporary password must be replaced before you can continue.</p>
      <form onSubmit={onSubmit} className="flex flex-col gap-3" noValidate>
        <Input type="password" label="Temporary password" autoComplete="current-password" value={current} onChange={(event) => setCurrent(event.target.value)} />
        <Input type="password" label="New password" autoComplete="new-password" minLength={minimumLength} value={password} onChange={(event) => setPassword(event.target.value)} />
        <PasswordStrength password={password} minimumLength={minimumLength} />
        <PasswordRequirements password={password} policy={policy} />
        <Input type="password" label="Confirm new password" autoComplete="new-password" minLength={minimumLength} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
        <PasswordMatchHint password={password} confirmation={confirmation} />
        <Button type="submit" variant="primary" loading={loading} disabled={loading || !valid} className="mt-2 w-full">
          {loading ? 'Updating...' : 'Update password'}
        </Button>
      </form>
    </Panel>
  );
}
