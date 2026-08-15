import { useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import gsap from 'gsap';
import { LuTriangleAlert, LuBuilding2, LuEye, LuEyeOff, LuPackage, LuTimer } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Button } from '@/components/ui/Button';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { actionLabel } from '@/lib/labels';
import { reduceMotion } from '@/pages/landing/motion';
import { useMagnetic } from '@/pages/landing/hooks/useMagnetic';
import { landingApi } from '@/api/landing';

const schema = z.object({
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  password: z.string().min(1, 'Password is required'),
});

type PortalLoginForm = z.infer<typeof schema>;
export type PortalType = 'supplier' | 'customer';

const PORTAL_CONFIG = {
  supplier: {
    label: 'Supplier portal',
    description: 'purchase orders, invoices, and deliveries',
    icon: LuBuilding2,
    login: supplierPortalApi.login,
    destination: '/portal/supplier',
    forgotPassword: '/portal/supplier/forgot-password',
  },
  customer: {
    label: 'Customer portal',
    description: 'orders, invoices, and account details',
    icon: LuPackage,
    login: customerPortalApi.login,
    destination: '/portal/customer',
    forgotPassword: '/portal/customer/forgot-password',
  },
} as const;

function formatCooldown(seconds: number): string {
  if (seconds >= 60) {
    const minutes = Math.floor(seconds / 60);
    return `${minutes}:${(seconds % 60).toString().padStart(2, '0')}`;
  }
  return `${seconds}s`;
}

/** Prefer the server's live lock/rate-limit duration over client guesses. */
function retryAfterSeconds(err: AxiosError<{ message?: string }>): number {
  const header = err.response?.headers?.['retry-after'];
  const headerSeconds = Number(Array.isArray(header) ? header[0] : header);
  if (Number.isFinite(headerSeconds) && headerSeconds > 0) return Math.ceil(headerSeconds);

  const message = err.response?.data?.message ?? '';
  const match = message.match(/(?:in|after)\s+(\d+)\s*(seconds?|minutes?)/i);
  if (!match) return 0;
  const amount = Number(match[1]);
  return match[2].toLowerCase().startsWith('minute') ? amount * 60 : amount;
}

export default function PortalLoginPage({ portalType }: { portalType: PortalType }) {
  const config = PORTAL_CONFIG[portalType];
  const RoleIcon = config.icon;
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const legalName = contact?.legal_name ?? '';
  const navigate = useNavigate();
  const [cooldown, setCooldown] = useState(0);
  const [showPassword, setShowPassword] = useState(false);
  const [capsOn, setCapsOn] = useState(false);
  const [passwordFocused, setPasswordFocused] = useState(false);
  const pageRef = useRef<HTMLDivElement>(null);
  const submitRef = useMagnetic<HTMLButtonElement>({ strength: 0.28, duration: 0.5 });

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
        { autoAlpha: 1, y: 0, duration: 0.55, ease: 'power3.out', stagger: 0.1, delay: 0.05 },
      );
    }, root);

    return () => ctx.revert();
  }, []);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setInterval(() => {
      setCooldown((previous) => Math.max(previous - 1, 0));
    }, 1000);
    return () => window.clearInterval(timer);
  }, [cooldown]);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<PortalLoginForm>({
    resolver: zodResolver(schema),
  });

  const onSubmit = async (data: PortalLoginForm) => {
    try {
      const user = await config.login(data.email, data.password);
      toast.success(`Signed in to ${config.label}.`);
      navigate(user.must_change_password ? `/portal/${portalType}/change-password` : config.destination, { replace: true });
    } catch (error) {
      const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = axiosError.response?.status;
      const body = axiosError.response?.data;

      if (status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, messages]) => {
          setError(field as keyof PortalLoginForm, {
            type: 'server',
            message: messages[0] ?? 'Invalid value.',
          });
        });
      } else if (status === 423) {
        toast.error(body?.message ?? 'Account locked. Try again later.');
        setCooldown(retryAfterSeconds(axiosError));
      } else if (status === 429) {
        toast.error('Too many attempts. Please wait a moment.');
        setCooldown(retryAfterSeconds(axiosError));
      } else if (!axiosError.response) {
        toast.error('Network error. Please check your connection.');
      } else {
        toast.error('Sign-in failed. Please try again.');
      }
    }
  };

  const handleCapsKey = (event: KeyboardEvent) => {
    setCapsOn(event.getModifierState('CapsLock'));
  };
  const isCooledDown = cooldown > 0;

  return (
    <div ref={pageRef}>
      <Panel>
        <div className="mb-6" data-entrance="header">
          <div className="flex items-center justify-between gap-3">
            <p className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-muted">
              <RoleIcon size={12} className="text-accent" />
              {config.label}
            </p>
          </div>
          <h1 className="mt-3 font-display text-2xl tracking-tight text-primary">Welcome back</h1>
          <p className="mt-1.5 text-[13px] text-muted">
            Sign in with your {config.label.replace(' portal', '')} email to manage {config.description}
            {legalName ? ` with ${legalName}` : ''}.
          </p>
        </div>

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
                    onClick={() => setShowPassword((value) => !value)}
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                    className="flex h-full items-center justify-center px-2 text-muted transition-colors hover:text-primary"
                  >
                    {showPassword ? <LuEyeOff size={15} /> : <LuEye size={15} />}
                  </button>
                }
              />
              <div aria-live="polite" className="mt-1.5 min-h-[1.25rem]">
                {capsOn && passwordFocused && (
                  <span className="flex items-center gap-1.5 font-mono text-[11px] text-warning-fg">
                    <LuTriangleAlert size={11} />
                    Caps Lock is on
                  </span>
                )}
              </div>
            </div>

            <div className="flex items-center justify-end">
              <Link
                to={config.forgotPassword}
                className="text-xs text-muted underline-offset-2 transition-colors hover:text-primary hover:underline"
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
              {isCooledDown ? `Retry in ${formatCooldown(cooldown)}` : actionLabel('Sign in', isSubmitting)}
            </Button>

            {isCooledDown && (
              <div role="status" aria-live="polite" className="flex flex-col items-center justify-center gap-1.5 text-xs text-warning-fg">
                <div className="flex items-center gap-1.5">
                  <LuTimer size={12} />
                  <span>Too many attempts — disabled for {formatCooldown(cooldown)}</span>
                </div>
                <span className="text-muted">Please wait before trying again.</span>
              </div>
            )}
          </form>
        </div>

        <div
          data-entrance="footer"
          className="mt-7 border-t border-default pt-5 text-center text-xs text-muted"
        >
          <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-2">
            <Link
              to={`/portal/${portalType === 'supplier' ? 'customer' : 'supplier'}/login`}
              className="underline-offset-2 transition-colors hover:text-primary hover:underline"
            >
              {portalType === 'supplier' ? 'Customer login instead' : 'Supplier login instead'}
            </Link>
            <span aria-hidden="true" className="text-text-subtle">·</span>
            <Link to="/login" className="underline-offset-2 transition-colors hover:text-primary hover:underline">
              Employee login
            </Link>
          </div>
        </div>
      </Panel>
    </div>
  );
}
