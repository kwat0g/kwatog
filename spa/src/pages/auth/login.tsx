import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Lock } from 'lucide-react';
import { AxiosError } from 'axios';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const login = useAuthStore((s) => s.login);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setErrors({});
    setIsSubmitting(true);

    try {
      const user = await login({ email, password });
      const from = (location.state as { from?: string } | null)?.from ?? '/dashboard';
      navigate(user.must_change_password ? '/change-password' : from, { replace: true });
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
      } else if (status === 423) {
        toast.error(data?.message ?? 'Account locked. Try again later.');
      } else if (status === 429) {
        toast.error('Too many attempts. Please wait a moment.');
      } else if (!axe.response) {
        toast.error('Network error. Please check your connection.');
      } else {
        toast.error('Sign-in failed. Please try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Panel>
      <div className="flex flex-col items-center mb-4">
        <span className="h-9 w-9 rounded-full bg-elevated text-muted inline-flex items-center justify-center mb-2">
          <Lock size={16} />
        </span>
        <h1 className="text-lg font-medium">Sign in to Ogami ERP</h1>
        <p className="text-xs text-muted mt-0.5">Use your work email and password.</p>
      </div>

      <form onSubmit={onSubmit} className="flex flex-col gap-3">
        <Input
          type="email"
          name="email"
          label="Email"
          autoComplete="email"
          autoFocus
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          error={errors.email}
        />
        <Input
          type="password"
          name="password"
          label="Password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={errors.password}
        />

        <Button
          type="submit"
          variant="primary"
          size="md"
          loading={isSubmitting}
          disabled={isSubmitting}
          className="mt-2 w-full"
        >
          {isSubmitting ? 'Signing in…' : 'Sign in'}
        </Button>

        <div className="text-xs text-muted text-center mt-1">
          <Link to="#" className="hover:text-primary">
            Forgot password?
          </Link>
        </div>
      </form>
    </Panel>
  );
}
