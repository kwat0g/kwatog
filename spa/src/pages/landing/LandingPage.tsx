/**
 * LandingPage — public, customer-facing marketing site for Philippine Ogami
 * Corporation. Light, monochrome canvas with a single warm-graphite accent, mirroring
 * the ERP design system; theme-independent, built to win trust and quotes.
 *
 * Owns the page-level motion lifecycle (smooth scroll + reveals) and composes
 * the section stack. The only internal/ERP affordance is the single "Login"
 * button in the nav (+ a discreet staff link in the footer).
 */

import { useEffect, useRef, useState, type CSSProperties } from 'react';

// Self-hosted display face (Fontsource → same-origin → CSP-safe).
import '@fontsource-variable/bricolage-grotesque/wght.css';

import { LandingNav } from './components/LandingNav';
import { LandingFooter } from './components/LandingFooter';
import { CookieBanner } from './components/CookieBanner';
import { BackToTop } from './components/BackToTop';
import { FloatingQuoteButton } from './components/FloatingQuoteButton';
import { HeroSection } from './sections/HeroSection';
import { MarqueeSection } from './sections/MarqueeSection';
import { TrustSection } from './sections/TrustSection';
import { CapabilitiesSection } from './sections/CapabilitiesSection';
import { PartsGallerySection } from './sections/PartsGallerySection';
import { ProcessSection } from './sections/ProcessSection';
import { StatsSection } from './sections/StatsSection';
import { QualitySection } from './sections/QualitySection';
import { PhilippinesSection } from './sections/PhilippinesSection';
import { ContactSection } from './sections/ContactSection';
import { useLandingMotion } from './motion';
import { COMPANY } from './data';

/**
 * Spread `inert` (+ aria-hidden) onto a wrapper when `active`, so background
 * content is unreachable by keyboard, pointer, and assistive tech while the
 * mobile menu overlay is open. Typed loosely because React 18's JSX types do
 * not yet include the `inert` attribute.
 */
function inertWhen(active: boolean): Record<string, unknown> {
  return active ? { inert: '', 'aria-hidden': true } : {};
}

/**
 * Remap the shared ERP accent variables to the landing-page espresso ink so
 * Button, Input, Checkbox, and other primitives render monochrome/warm here
 * without any component-level changes.
 */
const WARM_ACCENT: CSSProperties = {
  '--accent': 'var(--landing-accent)',
  '--accent-hover': 'var(--landing-accent-hover)',
  '--accent-fg': 'var(--landing-accent-fg)',
  '--ring': 'var(--landing-accent)',
  '--ring-offset': 'var(--landing-canvas)',
  '--shadow-focus': '0 0 0 3px var(--landing-accent-glow)',
} as CSSProperties;

export default function LandingPage() {
  const rootRef = useRef<HTMLDivElement>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  useLandingMotion(rootRef);

  useEffect(() => {
    const prev = document.title;
    document.title = `${COMPANY.legalName} — Precision Plastic Injection Molding`;
    return () => {
      document.title = prev;
    };
  }, []);

  return (
    <div
      ref={rootRef}
      style={WARM_ACCENT}
      className="min-h-screen bg-landing-canvas font-sans text-landing-text antialiased"
    >
      <a
        href="#capabilities"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-landing-accent focus:px-4 focus:py-2 focus:font-sans focus:text-sm focus:font-semibold focus:text-landing-accent-fg"
      >
        Skip to content
      </a>

      <LandingNav open={menuOpen} onOpenChange={setMenuOpen} />

      {/* While the mobile menu is open, hide page content from AT + pointer.
          `inert` is set via a ref-free attribute prop (cast) for RB18 typings. */}
      <main {...inertWhen(menuOpen)}>
        <HeroSection />
        <MarqueeSection />
        <TrustSection />
        <CapabilitiesSection />
        <PartsGallerySection />
        <ProcessSection />
        <StatsSection />
        <QualitySection />
        <PhilippinesSection />
        <ContactSection />
      </main>

      <div {...inertWhen(menuOpen)}>
        <LandingFooter />
      </div>

      <CookieBanner />
      <BackToTop />
      <FloatingQuoteButton />
    </div>
  );
}
