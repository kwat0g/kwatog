import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { authApi } from '@/api/auth';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';

export default function ChangePasswordPage() {
  const navigate = useNavigate();
  const refresh = useAuthStore((s) => s.refresh);

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const policy = [
    { test: (v: string) => v.length >= 8, label: 'At least 8 characters' },
    { test: (v: string) => /[A-Z]/.test(v), label: 'An uppercase letter' },
    { test: (v: string) => /[0-9]/.test(v), label: 'A digit' },
    { test: (v: string) => /[^A-Za-z0-9]/.test(v), label: 'A special character' },
  ];

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setErrors({});

    if (next !== confirm) {
      setErrors({ new_password_confirmation: 'Passwords do not match.' });
      return;
    }

    setIsSubmitting(true);
    try {
      await authApi.changePassword({
        current_password: current,
        new_password: next,
        new_password_confirmation: confirm,
      });
      toast.success('Password updated.');
      await refresh();
      navigate('/dashboard', { replace: true });
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = axe.response?.status;
      const data = axe.response?.data;

      if (status === 422 && data?.errors) {
        const mapped: Record<string, string> = {};
        for (const [field, messages] of Object.entries(data.errors)) {
          mapped[field] = messages[0] ?? 'Invalid value.';
        }
        setErrors(mapped);
      } else {
        toast.error(data?.message ?? 'Could not update password.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Panel title="Change password">
      <p className="text-sm text-muted mb-4">
        For your security, please choose a new password before continuing.
      </p>

      <form onSubmit={onSubmit} className="flex flex-col gap-3">
        <Input
          type="password"
          label="Current password"
          name="current_password"
          autoComplete="current-password"
          required
          value={current}
          onChange={(e) => setCurrent(e.target.value)}
          error={errors.current_password}
        />
        <Input
          type="password"
          label="New password"
          name="new_password"
          autoComplete="new-password"
          required
          value={next}
          onChange={(e) => setNext(e.target.value)}
          error={errors.new_password}
        />
        <Input
          type="password"
          label="Confirm new password"
          name="new_password_confirmation"
          autoComplete="new-password"
          required
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          error={errors.new_password_confirmation}
        />

        <ul className="text-xs space-y-0.5 mt-1">
          {policy.map((p) => (
            <li
              key={p.label}
              className={p.test(next) ? 'text-success' : 'text-muted'}
            >
              · {p.label}
            </li>
          ))}
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
