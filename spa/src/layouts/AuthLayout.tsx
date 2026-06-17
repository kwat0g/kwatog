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

import { useEffect, useLayoutEffect, useRef, type CSSProperties } from 'react';
import { Outlet, Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import gsap from 'gsap';
import { useThemeStore } from '@/stores/themeStore';
import { reduceMotion } from '@/pages/landing/motion';

// Self-hosted display face (Fontsource → same-origin → CSP-safe); the auth
// pages share the marketing site's display typeface for brand continuity.
import '@fontsource-variable/bricolage-grotesque/wght.css';

import { BrandLogo } from '@/components/brand/BrandLogo';
import { AutoPartShowcase } from '@/pages/landing/components/AutoPartShowcase';
import { CrosshairCursor } from '@/pages/landing/components/CrosshairCursor';

// Remap the app accent → the landing-page ink for the auth surfaces only
// (cascades into the shared Button/Input via var(--accent) / var(--ring)).
// Using CSS variables means light mode gets espresso-on-paper automatically.
const WARM_ACCENT = {
  '--accent': 'var(--landing-accent)',
  '--accent-hover': 'var(--landing-accent-hover)',
  '--accent-fg': 'var(--landing-accent-fg)',
  '--ring': 'var(--landing-accent)',
  '--shadow-focus': '0 0 0 3px var(--landing-accent-glow)',
} as CSSProperties;

const GRID_BG: CSSProperties = {
  backgroundImage:
    'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
    'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
  backgroundSize: 'var(--landing-grid-size, 32px) var(--landing-grid-size, 32px)',
};

export function AuthLayout() {
  // Public/auth pages are light-only. If no authenticated session has set a
  // theme yet, pin light (don't follow system → no dark auth surfaces).
  const initTheme = useThemeStore((s) => s.init);
  useEffect(() => {
    const existing = document.documentElement.getAttribute('data-theme');
    if (!existing) {
      initTheme('light');
    }
  }, [initTheme]);

  const asideRef = useRef<HTMLElement>(null);
  const gridRef = useRef<HTMLDivElement>(null);
  const scanRef = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const aside = asideRef.current;
    const grid = gridRef.current;
    const scan = scanRef.current;
    if (!aside || !grid || !scan) return;
    if (reduceMotion()) return;

    const ctx = gsap.context(() => {
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

    return () => ctx.revert();
  }, []);

  return (
    <div
      style={WARM_ACCENT}
      className="grid min-h-screen w-full bg-landing-canvas font-sans text-landing-text lg:grid-cols-2"
    >
      {/* ── Brand panel (lg+) ─────────────────────────────────────── */}
      <aside
        ref={asideRef}
        data-crosshair-scope
        className="relative hidden overflow-hidden border-r border-landing-border bg-landing-surface lg:flex lg:flex-col lg:justify-between lg:p-12"
      >
        <CrosshairCursor scopeRef={asideRef} />

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
          className="relative flex items-center gap-3 self-start rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-surface"
        >
          <BrandLogo alt="Ogami" className="h-10" />
          <span className="font-mono text-[10px] uppercase tracking-[0.22em] text-landing-muted">
            Philippines
          </span>
        </Link>

        {/* auto-cycling 3D parts tour inside a drawing frame */}
        <div className="relative mx-auto flex w-full max-w-sm items-center justify-center">
          <figure className="relative aspect-square w-full overflow-hidden rounded-xl border border-landing-border-strong bg-landing-canvas">
            {/* faint blueprint grid inside the frame */}
            <div
              aria-hidden="true"
              className="absolute inset-0"
              style={{
                backgroundImage:
                  'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
                  'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
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
                className={`absolute h-4 w-4 border-landing-border-strong ${pos}`}
              />
            ))}

            <AutoPartShowcase className="absolute inset-0" />

            {/* CMM scan-line — sweeps over the figure */}
            <div
              ref={scanRef}
              aria-hidden="true"
              className="pointer-events-none absolute inset-x-6 z-30 h-px bg-landing-accent/40"
              style={{ opacity: 0 }}
            />
          </figure>
        </div>

        {/* tagline */}
        <div className="relative">
          <p className="font-display text-2xl font-semibold leading-tight tracking-tight text-landing-text">
            Precision, molded
            <br /> in the Philippines.
          </p>
          <p className="mt-3 font-mono text-[11px] uppercase tracking-[0.18em] text-landing-subtle-text">
            IATF 16949 · FCIE, Dasmariñas · Cavite
          </p>
        </div>
      </aside>

      {/* ── Form area ─────────────────────────────────────────────── */}
      <main className="relative flex flex-col items-center justify-center px-5 py-12 sm:px-8">
        {/* compact brand for mobile (brand panel hidden) */}
        <Link to="/" className="mb-10 flex items-center rounded-md lg:hidden">
          <BrandLogo alt="Ogami" className="h-10" />
        </Link>

        <div className="w-full max-w-sm">
          <Outlet />
        </div>

        <Link
          to="/"
          className="mt-10 inline-flex items-center gap-1.5 rounded-md font-sans text-[13px] text-landing-muted transition-colors hover:text-landing-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas"
        >
          <ArrowLeft size={14} />
          Back to ogami.com.ph
        </Link>
      </main>
    </div>
  );
}

export default AuthLayout;
