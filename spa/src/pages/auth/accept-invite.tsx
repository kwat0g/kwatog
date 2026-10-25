/**
 * WS-A.1 — Public page: accept a self-service invite token.
 *
 * Reached via the link in the invite email: `/accept-invite?token=…`.
 * No auth required (the token IS the credential). On success the user is
 * sent to /login with a flash toast asking them to sign in with the email
 * they just confirmed.
 */
import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { ShieldCheck } from 'lucide-react';
import { userInvitesApi } from '@/api/admin/user-invites';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import type { ApiValidationError } from '@/types';

export default function AcceptInvitePage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';

  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [tokenInvalid, setTokenInvalid] = useState(false);

  useEffect(() => {
    if (!token) setTokenInvalid(true);
  }, [token]);

  if (tokenInvalid) {
    return (
      <Panel>
        <div className="flex flex-col items-center text-center gap-2">
          <span className="h-9 w-9 rounded-full bg-elevated text-muted inline-flex items-center justify-center">
            <ShieldCheck size={16} />
          </span>
          <h1 className="text-lg font-medium">Invite link is missing or invalid</h1>
          <p className="text-xs text-muted max-w-sm">
            Please open the link from the invite email exactly as it was sent. If the link expired,
            ask HR to issue a new invite.
          </p>
          <Link to="/login" className="mt-2 text-xs text-accent hover:underline">
            Go to sign-in
          </Link>
        </div>
      </Panel>
    );
  }

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setErrors({});

    if (password !== confirmation) {
      setErrors({ password_confirmation: 'Passwords do not match.' });
      return;
    }

    setSubmitting(true);
    try {
      await userInvitesApi.accept({
        token,
        name,
        password,
        password_confirmation: confirmation,
      });
      toast.success('Account created. Please sign in.');
      navigate('/login', { replace: true });
    } catch (err) {
      const axe = err as AxiosError<ApiValidationError>;
      const status = axe.response?.status;
      const data = axe.response?.data;

      if (status === 422 && data?.errors) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(data.errors)) flat[k] = v[0] ?? 'Invalid value.';
        setErrors(flat);
      } else if (status === 410) {
        setTokenInvalid(true);
      } else if (status === 404) {
        setTokenInvalid(true);
      } else if (status === 409) {
        toast.error(data?.message ?? 'This employee already has a portal account.');
      } else {
        toast.error('Could not create the account. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Panel>
      <div className="flex flex-col items-center mb-4">
        <span className="h-9 w-9 rounded-full bg-elevated text-muted inline-flex items-center justify-center mb-2">
          <ShieldCheck size={16} />
        </span>
        <h1 className="text-lg font-medium">Set up your portal account</h1>
        <p className="text-xs text-muted mt-0.5 text-center max-w-xs">
          Choose a password to activate self-service access for your employee record.
        </p>
      </div>

      <form onSubmit={onSubmit} className="flex flex-col gap-3">
        <Input
          type="text"
          label="Display name"
          autoComplete="name"
          autoFocus
          required
          value={name}
          onChange={(e) => setName(e.target.value)}
          error={errors.name}
        />
        <Input
          type="password"
          label="Password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={errors.password}
          helper="Minimum 8 characters with letters and numbers."
        />
        <Input
          type="password"
          label="Confirm password"
          autoComplete="new-password"
          required
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
          error={errors.password_confirmation}
        />

        <Button
          type="submit"
          variant="primary"
          size="md"
          loading={submitting}
          disabled={submitting}
          className="mt-2 w-full"
        >
          {submitting ? 'Setting up…' : 'Activate account'}
        </Button>

        <div className="text-xs text-muted text-center mt-1">
          Already activated?{' '}
          <Link to="/login" className="text-accent hover:underline">Sign in</Link>
        </div>
      </form>
    </Panel>
  );
}
