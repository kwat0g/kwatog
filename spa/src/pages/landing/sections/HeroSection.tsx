/**
 * HeroSection — "the part, drawn."
 *
 * Left: a confident display headline + value prop + one espresso CTA. Right: a
 * blueprint drawing frame holding the rotating wireframe part (WebGL) over a
 * static section view, with corner registration marks, a title block, and
 * dimension callouts that animate in. Light, monochrome, precise.
 *
 * The intro plays a staggered GSAP timeline; reduced-motion users see the final
 * composed frame and the static blueprint (no WebGL).
 */

import { useLayoutEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import gsap from 'gsap';
import { Link } from 'react-router-dom';
import { ArrowRight, Briefcase } from 'lucide-react';
import { HeroCanvas } from '../components/HeroCanvas';
import { PartBlueprint } from '../components/PartBlueprint';
import { ScrambleText } from '../components/ScrambleText';
import { landingApi } from '@/api/landing';
import { reduceMotion } from '../motion';
import { useMagnetic } from '../hooks/useMagnetic';

export function HeroSection() {
  const rootRef = useRef<HTMLElement>(null);
  const figureRef = useRef<HTMLElement>(null);
  const scanLineRef = useRef<HTMLDivElement>(null);

  const quoteRef = useMagnetic<HTMLAnchorElement>();
  const exploreRef = useMagnetic<HTMLAnchorElement>();
  const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });

  const legalName = contact?.legal_name || 'Philippine Ogami Corporation';
  const address = contact?.address || 'FCIE Dasmariñas, Cavite, Philippines';
  const trustPoints = content?.trust_points?.length ? content.trust_points : ['IATF 16949 Certified', 'Zero Defect Quality', 'Rapid Precision Tooling'];
  const partners = content?.oem_partners?.length ? content.oem_partners : ['Toyota', 'Nissan', 'Honda', 'Yamaha'];
  const qualityStandard = content?.quality_policy?.standard ?? content?.quality_methods?.[0] ?? 'IATF 16949';

  const defaultDesc = '{{company}} delivers IATF 16949 certified plastic injection molding, precision mold making, and automated assembly for Tier-1 automotive partners including {{partners}}.';
  const heroDescription = (content?.section_copy?.hero_description || defaultDesc)
    .replaceAll('{{company}}', legalName)
    .replaceAll('{{partners}}', partners.join(', '))
    .replaceAll('{{standard}}', qualityStandard)
    .replaceAll('{{address}}', address);

  const heroPart = content?.part_specs?.find((part) => part.id === 'filler-cap') ?? { name: 'Wiper Filler Cap', material: 'POM Grade A', tolerance: '±0.02mm' };
  const heroCopy = content?.hero_copy ?? { line_one: 'Precision Injection', line_two: 'Molding & Tooling', line_three: 'For OEM Leaders' };
  const heroCta = content?.section_copy?.hero_cta ?? {
    quote_label: 'Request Quote',
    quote_href: '#contact',
    explore_label: 'Explore Parts',
    explore_href: '#parts-3d',
    careers_label: 'Careers',
    careers_href: '/careers',
  };

  // Intro timeline
  useLayoutEffect(() => {
    const root = rootRef.current;
    if (!root || reduceMotion()) return;

    const ctx = gsap.context(() => {
      const tl = gsap.timeline({ defaults: { ease: 'power3.out' } });
      tl.from('[data-hero-line] > *', { yPercent: 115, duration: 1.05, stagger: 0.12 })
        .from('[data-hero="eyebrow"]', { autoAlpha: 0, y: 16, duration: 0.7 }, 0.1)
        .from('[data-hero="sub"]', { autoAlpha: 0, y: 20, duration: 0.8 }, '-=0.6')
        .from('[data-hero="cta"]', { autoAlpha: 0, y: 20, duration: 0.7 }, '-=0.55')
        .from('[data-hero="trust"] > *', { autoAlpha: 0, y: 14, duration: 0.6, stagger: 0.08 }, '-=0.5')
        .from('[data-hero="frame"]', { autoAlpha: 0, scale: 0.96, duration: 1.1 }, 0.2)
        .from('[data-hero-dim]', { autoAlpha: 0, duration: 0.6, stagger: 0.12 }, '-=0.5');
    }, root);

    return () => ctx.revert();
  }, []);

  // Scan-line sweep inside the drawing figure
  useLayoutEffect(() => {
    const fig = figureRef.current;
    const line = scanLineRef.current;
    if (!fig || !line || reduceMotion()) return;

    const ctx = gsap.context(() => {
      gsap.fromTo(
        line,
        { top: '0%', opacity: 0 },
        {
          top: '100%',
          opacity: 1,
          duration: 3.5,
          ease: 'none',
          repeat: -1,
          yoyo: false,
          keyframes: [
            { top: '0%', opacity: 0, duration: 0 },
            { top: '8%', opacity: 0.55, duration: 0.3 },
            { top: '92%', opacity: 0.45, duration: 2.9 },
            { top: '100%', opacity: 0, duration: 0.3 },
          ],
        },
      );
    }, fig);

    return () => ctx.revert();
  }, []);

  return (
    <section
      id="top"
      ref={rootRef}
      className="relative isolate overflow-hidden bg-canvas px-5 pb-20 pt-28 sm:px-5 lg:min-h-[100svh] lg:pt-32"
    >
      {/* Blueprint grid backdrop — parallax decorative layer */}
      <div
        aria-hidden="true"
        data-parallax="8"
        className="absolute left-0 right-0 top-[-12%] -z-10 h-[124%]"
        style={{
          backgroundImage:
            'linear-gradient(var(--blueprint-grid) 1px, transparent 1px),' +
            'linear-gradient(90deg, var(--blueprint-grid) 1px, transparent 1px)',
          backgroundSize: '32px 32px',
          maskImage: 'radial-gradient(130% 100% at 70% 30%, #000 45%, transparent 90%)',
          WebkitMaskImage: 'radial-gradient(130% 100% at 70% 30%, #000 45%, transparent 90%)',
        }}
      />

      <div className="mx-auto grid w-full max-w-[1440px] items-center gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:gap-10">
        {/* ── Text column ───────────────────────────────────────── */}
        <div>
          <p
            data-hero="eyebrow"
            className="flex items-center gap-2.5 font-mono text-[11px] uppercase tracking-[0.22em] text-muted"
          >
            <span className="h-1.5 w-1.5 rounded-full bg-accent" />
            <ScrambleText text={address} trigger="mount" />
          </p>

          <h1 className="mt-8 font-display text-[clamp(3rem,8vw,5.5rem)] font-semibold leading-[0.94] tracking-[-0.04em] text-transparent bg-clip-text bg-gradient-to-br from-primary via-primary to-secondary pb-2">
            <span data-hero-line className="block overflow-hidden">
              <span className="block">{heroCopy?.line_one ?? '—'}</span>
            </span>
            <span data-hero-line className="block overflow-hidden">
              <span className="block">{heroCopy?.line_two ?? '—'}</span>
            </span>
            <span data-hero-line className="block overflow-hidden">
              <span className="block">{heroCopy?.line_three ?? '—'}</span>
            </span>
          </h1>

          <p
            data-hero="sub"
            className="mt-8 max-w-2xl font-sans text-base font-light tracking-wide leading-relaxed text-secondary sm:text-xl"
          >
            {heroDescription}
          </p>

          <div data-hero="cta" className="mt-10 flex flex-col gap-4 sm:flex-row sm:items-center">
            <a
              ref={quoteRef}
              href={heroCta?.quote_href ?? '#'}
              className="group relative inline-flex items-center justify-center gap-2 overflow-hidden rounded-full bg-accent px-8 py-4 font-sans text-[15px] font-semibold text-accent-fg transition-all duration-300 hover:scale-105 hover:bg-accent-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
            >
              <span className="relative z-10 flex items-center gap-2">
                {heroCta?.quote_label ?? '—'}
                <ArrowRight size={18} className="transition-transform duration-300 group-hover:translate-x-1.5" />
              </span>
            </a>
            <a
              ref={exploreRef}
              href={heroCta?.explore_href ?? '#'}
              className="inline-flex items-center justify-center gap-2 rounded-full border border-strong px-8 py-4 font-sans text-[15px] font-medium text-primary transition-all duration-300 hover:scale-105 hover:border-primary hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
            >
              {heroCta?.explore_label ?? '—'}
            </a>
            <Link
              to={heroCta?.careers_href ?? '/'}
              className="inline-flex items-center justify-center gap-2 rounded-full border border-strong px-8 py-4 font-sans text-[15px] font-medium text-primary transition-all duration-300 hover:scale-105 hover:border-primary hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
            >
              <Briefcase size={16} />
              {heroCta?.careers_label ?? '—'}
            </Link>
          </div>

          <ul
            data-hero="trust"
            className="mt-12 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-default pt-6"
          >
            {trustPoints.map((item) => (
              <li
                key={item}
                className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.14em] text-muted"
              >
                <span className="h-1 w-1 rounded-full bg-accent" />
                {item}
              </li>
            ))}
          </ul>
        </div>

        {/* ── Drawing frame ─────────────────────────────────────── */}
        <div data-hero="frame" className="relative">
          <figure
            ref={figureRef}
            className="relative aspect-square w-full overflow-hidden rounded-md border border-strong bg-surface"
          >
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

            {/* static section view (base layer + WebGL fallback) */}
            <div className="absolute inset-0 flex items-center justify-center p-12">
              <PartBlueprint className="max-h-[78%] max-w-[78%] opacity-90" />
            </div>

            {/* rotating wireframe part */}
            <HeroCanvas />

            {/* Metrology scan-line sweep */}
            <div
              ref={scanLineRef}
              aria-hidden="true"
              className="pointer-events-none absolute left-0 right-0 h-px bg-accent/40"
              style={{ top: '0%' }}
            />

            {/* dimension callouts */}
            <span
              data-hero-dim
              className="absolute left-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-accent"
            >
              REV · A
            </span>
            <span
              data-hero-dim
              className="absolute right-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-accent"
            >
              {heroPart?.tolerance ?? '—'}
            </span>

            {/* title block */}
            <figcaption
              data-hero-dim
              className="absolute inset-x-3 bottom-3 grid grid-cols-3 overflow-hidden rounded-md border border-default bg-canvas/85 font-mono text-[9px] uppercase tracking-[0.12em] text-muted backdrop-blur-sm sm:text-[10px]"
            >
              <span className="border-r border-default px-3 py-2">
                <span className="block text-text-subtle">Part</span>
                <span className="text-primary">{heroPart?.name ?? '—'}</span>
              </span>
              <span className="border-r border-default px-3 py-2">
                <span className="block text-text-subtle">Material</span>
                <span className="text-primary">{heroPart?.material ?? '—'}</span>
              </span>
              <span className="px-3 py-2">
                <span className="block text-text-subtle">Std</span>
                <span className="text-primary">{qualityStandard}</span>
              </span>
            </figcaption>
          </figure>
        </div>
      </div>

      {/* Scroll cue */}
      <div
        aria-hidden="true"
        className="absolute bottom-6 left-1/2 -translate-x-1/2 flex flex-col items-center gap-1.5"
      >
        <span className="font-mono text-[9px] uppercase tracking-[0.22em] text-text-subtle">
          Scroll
        </span>
        <div className="relative h-8 w-px bg-border-default">
          <ScrollDot />
        </div>
      </div>
    </section>
  );
}

/** Animated dot that slides down the scroll cue rule on a loop. */
function ScrollDot() {
  const dotRef = useRef<HTMLSpanElement>(null);

  useLayoutEffect(() => {
    const dot = dotRef.current;
    if (!dot || reduceMotion()) return;

    const ctx = gsap.context(() => {
      gsap.fromTo(
        dot,
        { top: '0%', opacity: 0 },
        {
          top: '100%',
          opacity: 1,
          duration: 1.4,
          ease: 'power1.inOut',
          repeat: -1,
          keyframes: [
            { top: '0%', opacity: 0, duration: 0 },
            { top: '20%', opacity: 1, duration: 0.25 },
            { top: '80%', opacity: 1, duration: 0.9 },
            { top: '100%', opacity: 0, duration: 0.25 },
          ],
        },
      );
    }, dot);

    return () => ctx.revert();
  }, []);

  return (
    <span
      ref={dotRef}
      className="absolute left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-accent"
      style={{ top: '0%' }}
    />
  );
}
