import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Lock, Timer, ShieldCheck, Eye, EyeOff, AlertTriangle } from 'lucide-react';
import { AxiosError } from 'axios';
import gsap from 'gsap';
import { useAuthStore } from '@/stores/authStore';
import { useSidebarStore } from '@/stores/sidebarStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Checkbox } from '@/components/ui/Checkbox';
import { Panel } from '@/components/ui/Panel';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { actionLabel } from '@/lib/labels';
import { useMagnetic } from '@/pages/landing/hooks/useMagnetic';
import { reduceMotion } from '@/pages/landing/motion';

const schema = z.object({
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  password: z.string().min(1, 'Password is required'),
  remember: z.boolean().optional(),
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
  const [showPassword, setShowPassword] = useState(false);
  const [capsOn, setCapsOn] = useState(false);
  const [passwordFocused, setPasswordFocused] = useState(false);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const wasRedirected = !!(location.state as { from?: string } | null)?.from;

  // Entrance animation — wraps the whole form card
  const pageRef = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const root = pageRef.current;
    if (!root || reduceMotion()) return;

    const header = root.querySelector<HTMLElement>('[data-entrance="header"]');
    const fields = root.querySelector<HTMLElement>('[data-entrance="fields"]');
    const footer = root.querySelector<HTMLElement>('[data-entrance="footer"]');
    const targets = [header, fields, footer].filter(Boolean);

    const ctx = gsap.context(() => {
      gsap.fromTo(
        targets,
        { autoAlpha: 0, y: 14 },
        {
          autoAlpha: 1,
          y: 0,
          duration: 0.55,
          ease: 'power3.out',
          stagger: 0.1,
          delay: 0.05,
        },
      );
    }, root);

    return () => ctx.revert();
  }, []);

  // Magnetic submit button
  const submitRef = useMagnetic<HTMLButtonElement>({ strength: 0.28, duration: 0.5 });

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
    defaultValues: { remember: false },
  });

  const onSubmit = async (data: LoginForm) => {
    try {
      const user = await login({
        email: data.email,
        password: data.password,
        remember: data.remember,
      });
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

  // Caps Lock detection helpers
  function handleCapsKey(e: React.KeyboardEvent) {
    if (e.getModifierState) {
      setCapsOn(e.getModifierState('CapsLock'));
    }
  }

  return (
    <div ref={pageRef}>
      <Panel>
        {/* Header block */}
        <div className="mb-6" data-entrance="header">
          <p className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-landing-muted">
            <Lock size={12} className="text-landing-accent" />
            Secure sign-in
          </p>
          <h1 className="mt-3 font-display text-3xl font-bold tracking-tight text-landing-text">
            Welcome back
          </h1>
          <p className="mt-1.5 text-[13px] text-landing-muted">
            Sign in with your work email to access the Ogami ERP.
          </p>
        </div>

        {wasRedirected && (
          <div
            role="status"
            className="mb-5 rounded-lg border border-landing-accent/20 bg-landing-accent-glow px-4 py-3 text-[13px] text-landing-text-secondary"
          >
            Your session expired. Please sign in again to continue.
          </div>
        )}

        {/* Form fields */}
        <div data-entrance="fields">
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

            <div>
              <Input
                type={showPassword ? 'text' : 'password'}
                label="Password"
                autoComplete="current-password"
                disabled={isCooledDown}
                {...register('password')}
                error={errors.password?.message}
                onFocus={() => setPasswordFocused(true)}
                onBlur={() => setPasswordFocused(false)}
                onKeyUp={handleCapsKey}
                onKeyDown={handleCapsKey}
                suffix={
                  <button
                    type="button"
                    tabIndex={-1}
                    onClick={() => setShowPassword((v) => !v)}
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                    className="flex h-full items-center justify-center px-2 text-landing-muted transition-colors hover:text-landing-text"
                  >
                    {showPassword ? <EyeOff size={15} /> : <Eye size={15} />}
                  </button>
                }
              />
              {/* Caps Lock warning — only when password field is focused */}
              <div aria-live="polite" className="mt-1.5 min-h-[1.25rem]">
                {capsOn && passwordFocused && (
                  <span className="flex items-center gap-1.5 font-mono text-[11px] text-warning">
                    <AlertTriangle size={11} />
                    Caps Lock is on
                  </span>
                )}
              </div>
            </div>

            <div className="flex items-center justify-between">
              <Checkbox label="Remember me" {...register('remember')} />
              <Link
                to="/forgot-password"
                className="text-xs text-landing-muted underline-offset-2 transition-colors hover:text-landing-text hover:underline"
              >
                Forgot password?
              </Link>
            </div>

            <Button
              ref={submitRef}
              type="submit"
              variant="primary"
              size="lg"
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
                className="flex flex-col items-center justify-center gap-1.5 text-xs text-warning"
              >
                <div className="flex items-center gap-1.5">
                  <Timer size={12} />
                  <span>Too many attempts — disabled for {formatCooldown(cooldown)}</span>
                </div>
                <span className="text-landing-muted">
                  Need access now?{' '}
                  <a
                    href="mailto:it@ogami.com.ph?subject=Account%20locked"
                    className="underline-offset-2 hover:text-landing-text hover:underline"
                  >
                    Contact IT
                  </a>
                </span>
              </div>
            )}
          </form>
        </div>

        {/* Security footer */}
        <p
          data-entrance="footer"
          className="mt-7 flex items-center justify-center gap-1.5 border-t border-landing-border pt-5 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-subtle-text"
        >
          <ShieldCheck size={12} />
          Your account is protected against unauthorized access
        </p>
      </Panel>
    </div>
  );
}
