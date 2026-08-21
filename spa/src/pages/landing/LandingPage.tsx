/**
 * LandingPage — public, customer-facing marketing site.
 * Corporation. Light, monochrome canvas with a single warm-graphite accent, mirroring
 * the ERP design system; theme-independent, built to win trust and quotes.
 *
 * Owns the page-level motion lifecycle (smooth scroll + reveals) and composes
 * the section stack. The nav keeps employee ERP access primary and exposes
 * customer/supplier partner portals as a separate external-access group.
 */

import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

// Self-hosted display face (Fontsource → same-origin → CSP-safe).

import { LandingNav } from './components/LandingNav';
import { LandingFooter } from './components/LandingFooter';
import { CookieBanner } from './components/CookieBanner';
import { BackToTop } from './components/BackToTop';
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
import { SkeletonLandingPage } from '@/components/ui/Skeleton';
import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { landingApi, type LandingContact, type LandingContent } from '@/api/landing';

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
  const contactQuery = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const contentQuery = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });

  if (contactQuery.isPending || contentQuery.isPending) {
    return <SkeletonLandingPage />;
  }

  if (contactQuery.isError || contentQuery.isError) {
    return (
      <QueryErrorState
        subject="the landing page"
        onRetry={() => {
          void Promise.all([contactQuery.refetch(), contentQuery.refetch()]);
        }}
      />
    );
  }

  return <LandingPageContent contact={contactQuery.data} content={contentQuery.data} />;
}

function LandingPageContent({ contact, content }: { contact: LandingContact; content: LandingContent }) {
  const rootRef = useRef<HTMLDivElement>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  useLandingMotion(rootRef);

  useEffect(() => {
    const prev = document.title;
    const company = contact?.legal_name ?? '';
    const suffix = content?.section_copy?.page_title_suffix ?? '';
    const title = [company, suffix].filter(Boolean).join(' — ');
    if (title) document.title = title;
    return () => {
      document.title = prev;
    };
  }, [contact?.legal_name, content?.section_copy?.page_title_suffix]);

  useEffect(() => {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="description"]');
    if (!meta) return;
    const previous = meta.content;
    const company = contact?.legal_name ?? '';
    const partners = content?.oem_partners?.join(', ') ?? '';
    const standard = content?.quality_policy?.standard ?? '';
    const address = contact?.address ?? '';

    const description = (content?.section_copy?.hero_description ?? '')
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
      className="min-h-screen overflow-x-clip bg-canvas font-sans text-primary antialiased"
    >
      <ScrollProgress />
      <a
        href={content?.section_copy?.nav_links?.[0]?.href ?? '#'}
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:rounded-md focus:bg-accent focus:px-4 focus:py-2 focus:font-sans focus:text-sm focus:font-medium focus:text-accent-fg"
      >
        Skip to content
      </a>

      <LandingNav open={menuOpen} onOpenChange={setMenuOpen} />

      {/* While the mobile menu is open, hide page content from AT + pointer.
          `inert` is set via a ref-free attribute prop (cast) for RB18 typings. */}
      <main {...inertWhen(menuOpen)}>
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

      <div {...inertWhen(menuOpen)}>
        <LandingFooter />
      </div>

      <CookieBanner />
      <BackToTop />
    </div>
  );
}
