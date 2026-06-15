import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Lock, Timer } from 'lucide-react';
import { AxiosError } from 'axios';
import { useAuthStore } from '@/stores/authStore';
import { useSidebarStore } from '@/stores/sidebarStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { actionLabel } from '@/lib/labels';

const schema = z.object({
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  password: z.string().min(1, 'Password is required'),
});

type LoginForm = z.infer<typeof schema>;

function formatCooldown(seconds: number): string {
  if (seconds >= 60) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
  }
  return `${seconds}s`;
}

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const login = useAuthStore((s) => s.login);

  const [cooldown, setCooldown] = useState(0);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Tick the cooldown timer once per second while active.
  useEffect(() => {
    if (cooldown <= 0) return;
    timerRef.current = setInterval(() => {
      setCooldown((prev) => {
        if (prev <= 1) {
          clearInterval(timerRef.current!);
          timerRef.current = null;
          return 0;
        }
        return prev - 1;
      });
    }, 1000);
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
    };
  }, [cooldown > 0]); // eslint-disable-line react-hooks/exhaustive-deps

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({
    resolver: zodResolver(schema),
  });

  const onSubmit = async (data: LoginForm) => {
    try {
      const user = await login(data);
      useSidebarStore.getState().init(user.sidebar_collapsed);
      const from = (location.state as { from?: string } | null)?.from ?? '/dashboard';
      navigate(user.must_change_password ? '/change-password' : from, { replace: true });
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = axe.response?.status;
      const body = axe.response?.data;

      if (status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof LoginForm, {
            type: 'server',
            message: msgs[0] ?? 'Invalid value.',
          });
        });
      } else if (status === 423) {
        toast.error(body?.message ?? 'Account locked. Try again later.');
        setCooldown(900); // 15-minute lockout per backend policy
      } else if (status === 429) {
        toast.error('Too many attempts. Please wait a moment.');
        setCooldown(60); // 1-minute rate-limit cooldown
      } else if (!axe.response) {
        toast.error('Network error. Please check your connection.');
      } else {
        toast.error('Sign-in failed. Please try again.');
      }
    }
  };

  const isCooledDown = cooldown > 0;

  return (
    <Panel>
      <div className="flex flex-col items-center mb-4">
        <span className="h-9 w-9 rounded-full bg-elevated text-muted inline-flex items-center justify-center mb-2">
          <Lock size={16} />
        </span>
        <h1 className="text-lg font-medium">Sign in to Ogami ERP</h1>
        <p className="text-xs text-muted mt-0.5">Use your work email and password.</p>
      </div>

      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3" noValidate>
        <FormErrorSummary errors={errors} />
        <Input
          type="email"
          label="Email"
          autoComplete="email"
          autoFocus
          disabled={isCooledDown}
          {...register('email')}
          error={errors.email?.message}
        />
        <Input
          type="password"
          label="Password"
          autoComplete="current-password"
          disabled={isCooledDown}
          {...register('password')}
          error={errors.password?.message}
        />

        <Button
          type="submit"
          variant="primary"
          size="md"
          loading={isSubmitting}
          disabled={isSubmitting || isCooledDown}
          className="mt-2 w-full"
        >
          {isCooledDown
            ? `Retry in ${formatCooldown(cooldown)}`
            : actionLabel('Sign in', isSubmitting)}
        </Button>

        {isCooledDown && (
          <div
            role="status"
            aria-live="polite"
            className="flex items-center justify-center gap-1.5 text-xs text-warning"
          >
            <Timer size={12} />
            <span>Too many attempts — disabled for {formatCooldown(cooldown)}</span>
          </div>
        )}

        <div className="text-xs text-muted text-center mt-1">
          <Link to="#" className="hover:text-primary">
            Forgot password?
          </Link>
        </div>
      </form>
    </Panel>
  );
}
