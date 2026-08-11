/**
 * AuthLayout — branded split-screen shell for sign-in and change-password.
 *
 * Left: a precision "CMM stage" brand panel matching the marketing site —
 * warm paper, blueprint grid (parallax-on-pointer), the rotating part (or its
 * static blueprint under reduced-motion), dimension callouts, a slow scan-line,
 * a datum-mark wordmark, and a title block. Hidden below lg.
 * Right: the auth form (the routed Outlet), centered on warm paper.
 *
 * The whole shell pins the warm-graphite identity by locally remapping the
 * accent CSS variables to espresso, so the shared ERP form controls (Button,
 * Input) render blue-free here without any change to those components.
 */

import { lazy, Suspense, useEffect, useLayoutEffect, useRef, type CSSProperties } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Outlet, Link, useLocation } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { useThemeStore } from '@/stores/themeStore';
import { reduceMotion } from '@/lib/motionPrefs';

// Self-hosted display face (Fontsource → same-origin → CSP-safe); the auth
// pages share the marketing site's display typeface for brand continuity.

import { BrandLogo } from '@/components/brand/BrandLogo';
import { landingApi } from '@/api/landing';

// `AuthLayout` is reachable statically from `App.tsx` (via `authRoutes`), so a
// static import here lands in the entry chunk for EVERY route — someone deep
// linking to /dashboard would download Three.js before anything painted. The
// showcase is decorative and `hidden` below `lg`, so defer it: the drawing
// frame, grid and registration marks render immediately either way.
const AutoPartShowcase = lazy(() =>
  import('@/pages/landing/components/AutoPartShowcase').then((m) => ({
    default: m.AutoPartShowcase,
  })),
);

const GRID_BG: CSSProperties = {
  backgroundImage:
    'linear-gradient(var(--blueprint-grid) 1px, transparent 1px),' +
    'linear-gradient(90deg, var(--blueprint-grid) 1px, transparent 1px)',
  backgroundSize: 'var(--blueprint-grid-size, 32px) var(--blueprint-grid-size, 32px)',
};

export function AuthLayout() {
  const location = useLocation();
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const legalName = contact?.legal_name ?? '';
  const locationCountry = contact?.address?.split(',').at(-1)?.trim() ?? '';
  const address = contact?.address ?? '';

  // Public/auth pages are light-only. If no authenticated session has set a
  // theme yet, pin light (don't follow system → no dark auth surfaces).
  const initTheme = useThemeStore((s) => s.init);
  useEffect(() => {
    const existing = document.documentElement.getAttribute('data-theme');
    if (!existing) {
      initTheme('light');
    }
  }, [initTheme]);

  useEffect(() => {
    const labels: Record<string, string> = {
      '/login': 'Sign in',
      '/forgot-password': 'Forgot password',
      '/reset-password': 'Reset password',
      '/change-password': 'Change password',
    };
    document.title = `${labels[location.pathname] ?? 'Account'} · ERP`;
  }, [location.pathname]);

  const asideRef = useRef<HTMLElement>(null);
  const gridRef = useRef<HTMLDivElement>(null);
  const scanRef = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const aside = asideRef.current;
    const grid = gridRef.current;
    const scan = scanRef.current;
    if (!aside || !grid || !scan) return;
    if (reduceMotion()) return;

    // GSAP is loaded on demand so it stays out of the entry chunk (see the
    // note on AutoPartShowcase above). Both decorations are ambient, so
    // starting a tick or two late is imperceptible; `disposed` covers an
    // unmount that beats the import.
    let disposed = false;
    let ctx: { revert: () => void } | undefined;

    void import('gsap').then(({ default: gsap }) => {
      if (disposed) return;

      ctx = gsap.context(() => {
        // ── CMM scan-line — slow vertical sweep across the figure ───────
        // Animate `top` (0%→100% of the figure) — a 1px-tall line cannot be
        // swept with yPercent (that is relative to its own height).
        gsap.fromTo(
          scan,
          { top: '0%', opacity: 0 },
          {
            duration: 3.5,
            ease: 'none',
            repeat: -1,
            repeatDelay: 0.8,
            keyframes: [
              { top: '0%', opacity: 0, duration: 0 },
              { top: '6%', opacity: 0.5, duration: 0.3 },
              { top: '94%', opacity: 0.4, duration: 2.9 },
              { top: '100%', opacity: 0, duration: 0.3 },
            ],
          },
        );

        // ── Grid parallax — pointer depth on the aside ───────────────────
        const gx = gsap.quickTo(grid, 'x', { duration: 0.9, ease: 'power3.out' });
        const gy = gsap.quickTo(grid, 'y', { duration: 0.9, ease: 'power3.out' });

        function onPointerMove(e: PointerEvent) {
          const r = aside!.getBoundingClientRect();
          const rx = (e.clientX - r.left) / r.width - 0.5;
          const ry = (e.clientY - r.top) / r.height - 0.5;
          gx(rx * 10);
          gy(ry * 10);
        }
        function onPointerLeave() {
          gx(0);
          gy(0);
        }

        aside.addEventListener('pointermove', onPointerMove, { passive: true });
        aside.addEventListener('pointerleave', onPointerLeave, { passive: true });

        return () => {
          aside.removeEventListener('pointermove', onPointerMove);
          aside.removeEventListener('pointerleave', onPointerLeave);
        };
      }, aside);
    });

    return () => {
      disposed = true;
      ctx?.revert();
    };
  }, []);

  return (
    <div className="grid min-h-screen w-full bg-canvas font-sans text-primary lg:grid-cols-2">
      {/* ── Brand panel (lg+) ─────────────────────────────────────── */}
      <aside
        ref={asideRef}
        className="relative hidden overflow-hidden border-r border-default bg-surface lg:flex lg:flex-col lg:justify-between lg:p-12"
      >
        {/* Grid background — receives pointer parallax via gridRef */}
        <div
          ref={gridRef}
          aria-hidden="true"
          className="absolute inset-0 will-change-transform"
          style={GRID_BG}
        />

        {/* brand */}
        <Link
          to="/"
          className="relative flex shrink-0 items-center gap-3 self-start rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
        >
          <BrandLogo alt={legalName} className="h-10 shrink-0" />
          <div className="flex flex-col text-left">
            <span className=" text-sm tracking-tight text-primary leading-tight whitespace-nowrap">
              {legalName}
            </span>
            <span className="font-mono text-[9px] uppercase tracking-[0.2em] text-muted whitespace-nowrap">
              {locationCountry}
            </span>
          </div>
        </Link>

        {/* auto-cycling 3D parts tour inside a drawing frame */}
        <div className="relative mx-auto flex w-full max-w-sm items-center justify-center">
          <figure className="relative aspect-square w-full overflow-hidden rounded-md border border-strong bg-canvas">
            {/* faint blueprint grid inside the frame */}
            <div
              aria-hidden="true"
              className="absolute inset-0"
              style={{
                backgroundImage:
                  'linear-gradient(var(--blueprint-grid) 1px, transparent 1px),' +
                  'linear-gradient(90deg, var(--blueprint-grid) 1px, transparent 1px)',
                backgroundSize: '28px 28px',
                maskImage: 'radial-gradient(120% 100% at 50% 50%, #000 40%, transparent 92%)',
                WebkitMaskImage: 'radial-gradient(120% 100% at 50% 50%, #000 40%, transparent 92%)',
              }}
            />

            {/* corner registration marks */}
            {[
              'left-3 top-3 border-l border-t',
              'right-3 top-3 border-r border-t',
              'left-3 bottom-3 border-b border-l',
              'right-3 bottom-3 border-b border-r',
            ].map((pos) => (
              <span
                key={pos}
                aria-hidden="true"
                className={`absolute h-4 w-4 border-strong ${pos}`}
              />
            ))}

            <Suspense fallback={null}>
              <AutoPartShowcase className="absolute inset-0" />
            </Suspense>

            {/* CMM scan-line — sweeps over the figure */}
            <div
              ref={scanRef}
              aria-hidden="true"
              className="pointer-events-none absolute inset-x-6 z-30 h-px bg-accent/40"
              style={{ opacity: 0 }}
            />
          </figure>
        </div>

        {/* tagline */}
        <div className="relative">
          <p className="font-display text-2xl leading-tight tracking-tight text-primary">
            Precision, molded
            {locationCountry ? (
              <>
                <br /> in {locationCountry}.
              </>
            ) : (
              '—'
            )}
          </p>
          <p className="mt-3 font-mono text-[11px] uppercase tracking-[0.18em] text-text-subtle">
            {address}
          </p>
        </div>
      </aside>

      {/* ── Form area ─────────────────────────────────────────────── */}
      <main className="relative flex flex-col items-center justify-center px-5 py-12 sm:px-5">
        {/* compact brand for mobile (brand panel hidden) */}
        <Link to="/" className="mb-10 flex shrink-0 items-center gap-3 rounded-md lg:hidden">
          <BrandLogo alt={legalName} className="h-10 shrink-0" />
          <div className="flex flex-col text-left">
            <span className=" text-sm tracking-tight text-primary leading-tight whitespace-nowrap">
              {legalName}
            </span>
            <span className="font-mono text-[9px] uppercase tracking-[0.2em] text-muted whitespace-nowrap">
              ERP
            </span>
          </div>
        </Link>

        <div className="w-full max-w-sm">
          <Outlet />
        </div>

        <Link
          to="/"
          className="mt-10 inline-flex items-center gap-1.5 rounded-md font-sans text-[13px] text-muted transition-colors hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
        >
          <ArrowLeft size={14} />
          Back to {legalName || 'home'}
        </Link>
      </main>
    </div>
  );
}

export default AuthLayout;
