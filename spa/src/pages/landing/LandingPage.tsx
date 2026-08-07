/**
 * LandingPage — public, customer-facing marketing site.
 * Corporation. Light, monochrome canvas with a single warm-graphite accent, mirroring
 * the ERP design system; theme-independent, built to win trust and quotes.
 *
 * Owns the page-level motion lifecycle (smooth scroll + reveals) and composes
 * the section stack. The only internal/ERP affordance is the single "Login"
 * button in the nav (+ a discreet staff link in the footer).
 */

import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

// Self-hosted display face (Fontsource → same-origin → CSP-safe).

import { LandingNav } from './components/LandingNav';
import { LandingFooter } from './components/LandingFooter';
import { CookieBanner } from './components/CookieBanner';
import { BackToTop } from './components/BackToTop';
import { FloatingQuoteButton } from './components/FloatingQuoteButton';
import { QuoteModal } from './components/QuoteModal';
import { CrosshairCursor } from './components/CrosshairCursor';
import { ScrollProgress } from './components/ScrollProgress';
import { HeroSection } from './sections/HeroSection';
import { MarqueeSection } from './sections/MarqueeSection';
import { CapabilitiesSection } from './sections/CapabilitiesSection';
import { PartShowcaseSection } from './sections/PartShowcaseSection';
import { ProcessSection } from './sections/ProcessSection';
import { StatsSection } from './sections/StatsSection';
import { QualitySection } from './sections/QualitySection';
import { PhilippinesSection } from './sections/PhilippinesSection';
import { ContactSection } from './sections/ContactSection';
import { useLandingMotion } from './motion';
import { landingApi } from '@/api/landing';

/**
 * Spread `inert` (+ aria-hidden) onto a wrapper when `active`, so background
 * content is unreachable by keyboard, pointer, and assistive tech while the
 * mobile menu overlay is open. Typed loosely because React 18's JSX types do
 * not yet include the `inert` attribute.
 */
function inertWhen(active: boolean): Record<string, unknown> {
  return active ? { inert: '', 'aria-hidden': true } : {};
}

export default function LandingPage() {
  const rootRef = useRef<HTMLDivElement>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  const [quoteOpen, setQuoteOpen] = useState(false);
  const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  useLandingMotion(rootRef);

  useEffect(() => {
    const prev = document.title;
    const company = contact?.legal_name || 'Philippine Ogami Corporation';
    const suffix = content?.section_copy?.page_title_suffix || 'Precision Injection Molding & ERP';
    document.title = `${company} — ${suffix}`;
    return () => {
      document.title = prev;
    };
  }, [contact?.legal_name, content?.section_copy?.page_title_suffix]);

  useEffect(() => {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="description"]');
    if (!meta) return;
    const previous = meta.content;
    const company = contact?.legal_name || 'Philippine Ogami Corporation';
    const partners = content?.oem_partners?.length ? content.oem_partners.join(', ') : 'Toyota, Nissan, Honda, Yamaha';
    const standard = content?.quality_policy?.standard || 'IATF 16949';
    const address = contact?.address || 'FCIE Dasmariñas, Cavite, Philippines';
    const defaultDesc = '{{company}} delivers IATF 16949 certified plastic injection molding, precision mold making, and automated assembly for Tier-1 automotive partners.';

    const description = (content?.section_copy?.hero_description || defaultDesc)
      ?.replaceAll('{{company}}', company)
      ?.replaceAll('{{partners}}', partners)
      ?.replaceAll('{{standard}}', standard)
      ?.replaceAll('{{address}}', address);
    if (description) meta.content = description;
    return () => {
      meta.content = previous;
    };
  }, [contact?.address, contact?.legal_name, content]);

  return (
    <div
      ref={rootRef}
      data-crosshair-scope
      className="min-h-screen bg-canvas font-sans text-primary antialiased"
    >
      <ScrollProgress />
      <CrosshairCursor scopeRef={rootRef} />
      <a
        href={content?.section_copy?.nav_links?.[0]?.href ?? '#'}
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:rounded-md focus:bg-accent focus:px-4 focus:py-2 focus:font-sans focus:text-sm focus:font-medium focus:text-accent-fg"
      >
        Skip to content
      </a>

      <LandingNav open={menuOpen} onOpenChange={setMenuOpen} onOpenQuote={() => setQuoteOpen(true)} />

      {/* While the mobile menu is open, hide page content from AT + pointer.
          `inert` is set via a ref-free attribute prop (cast) for RB18 typings. */}
      <main {...inertWhen(menuOpen || quoteOpen)}>
        <HeroSection />
        <MarqueeSection />
        <CapabilitiesSection />
        <PartShowcaseSection />
        <ProcessSection />
        <QualitySection />
        <StatsSection />
        <PhilippinesSection />
        <ContactSection />
      </main>

      <div {...inertWhen(menuOpen || quoteOpen)}>
        <LandingFooter />
      </div>

      <CookieBanner />
      <BackToTop />
      <FloatingQuoteButton onOpenQuote={() => setQuoteOpen(true)} />
      <QuoteModal open={quoteOpen} onClose={() => setQuoteOpen(false)} />
    </div>
  );
}
