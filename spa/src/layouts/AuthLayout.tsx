/**
 * AuthLayout — branded split-screen shell for sign-in and change-password.
 *
 * Left: a precision "drawing frame" brand panel matching the marketing site —
 * warm paper, blueprint grid, the rotating part (or its static blueprint under
 * reduced-motion), a datum-mark wordmark, and a title block. Hidden below lg.
 * Right: the auth form (the routed Outlet), centered on warm paper.
 *
 * The whole shell pins the warm-graphite identity by locally remapping the
 * accent CSS variables to espresso, so the shared ERP form controls (Button,
 * Input) render blue-free here without any change to those components.
 */

import { useEffect, type CSSProperties } from 'react';
import { Outlet, Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { useThemeStore } from '@/stores/themeStore';

// Self-hosted display face (Fontsource → same-origin → CSP-safe); the auth
// pages share the marketing site's display typeface for brand continuity.
import '@fontsource-variable/bricolage-grotesque/wght.css';

import { BrandLogo } from '@/components/brand/BrandLogo';
import { PartBlueprint } from '@/pages/landing/components/PartBlueprint';
import { HeroCanvas } from '@/pages/landing/components/HeroCanvas';

// Remap the app accent → the landing-page ink for the auth surfaces only
// (cascades into the shared Button/Input via var(--accent) / var(--ring)).
// Using CSS variables means light mode gets espresso-on-paper and dark mode
// gets cream-on-espresso automatically.
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
  // Before login there is no stored user preference, so respect the system
  // color scheme. Once the user logs in, authStore.applyUser() will already
  // have set data-theme, so we leave it alone.
  const initTheme = useThemeStore((s) => s.init);
  useEffect(() => {
    const existing = document.documentElement.getAttribute('data-theme');
    if (!existing) {
      initTheme('system');
    }
  }, [initTheme]);

  return (
    <div
      style={WARM_ACCENT}
      className="grid min-h-screen w-full bg-landing-canvas font-sans text-landing-text lg:grid-cols-2"
    >
      {/* ── Brand panel (lg+) ─────────────────────────────────────── */}
      <aside className="relative hidden overflow-hidden border-r border-landing-border bg-landing-surface lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div aria-hidden="true" className="absolute inset-0" style={GRID_BG} />

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

        {/* rotating part inside a drawing frame */}
        <div className="relative mx-auto flex w-full max-w-sm items-center justify-center">
          <figure className="relative aspect-square w-full">
            <div className="absolute inset-0 flex items-center justify-center p-10">
              <PartBlueprint className="max-h-[80%] max-w-[80%] opacity-90" />
            </div>
            <HeroCanvas />
            <figcaption className="absolute inset-x-0 bottom-0 flex justify-between font-mono text-[10px] uppercase tracking-[0.16em] text-landing-muted">
              <span>Wiper bushing</span>
              <span className="text-landing-accent">Ø 24.0 ±0.02</span>
            </figcaption>
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
