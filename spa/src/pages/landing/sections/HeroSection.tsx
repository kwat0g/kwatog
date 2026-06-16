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
import gsap from 'gsap';
import { ArrowRight } from 'lucide-react';
import { HeroCanvas } from '../components/HeroCanvas';
import { PartBlueprint } from '../components/PartBlueprint';
import { COMPANY } from '../data';
import { reduceMotion } from '../motion';

const TRUST = ['IATF 16949 Certified', '5 OEM partners', '≤10 PPM defect target'];

export function HeroSection() {
  const rootRef = useRef<HTMLElement>(null);

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

  return (
    <section
      id="top"
      ref={rootRef}
      className="relative isolate overflow-hidden bg-landing-canvas px-5 pb-20 pt-28 sm:px-8 lg:min-h-[100svh] lg:pt-32"
    >
      {/* Blueprint grid backdrop */}
      <div
        aria-hidden="true"
        className="absolute inset-0 -z-10"
        style={{
          backgroundImage:
            'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
            'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
          backgroundSize: '32px 32px',
          maskImage: 'radial-gradient(130% 100% at 70% 30%, #000 45%, transparent 90%)',
          WebkitMaskImage: 'radial-gradient(130% 100% at 70% 30%, #000 45%, transparent 90%)',
        }}
      />

      <div className="mx-auto grid w-full max-w-7xl items-center gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:gap-10">
        {/* ── Text column ───────────────────────────────────────── */}
        <div>
          <p
            data-hero="eyebrow"
            className="flex items-center gap-2.5 font-mono text-[11px] uppercase tracking-[0.22em] text-landing-muted"
          >
            <span className="h-1.5 w-1.5 rounded-full bg-landing-accent" />
            {COMPANY.locationLine}
          </p>

          <h1 className="mt-6 font-display text-[clamp(2.5rem,6vw,4.75rem)] font-bold leading-[0.98] tracking-[-0.03em] text-landing-text">
            <span data-hero-line className="block overflow-hidden">
              <span className="block">Precision the</span>
            </span>
            <span data-hero-line className="block overflow-hidden">
              <span className="block">world trusts,</span>
            </span>
            <span data-hero-line className="block overflow-hidden">
              <span className="block">made in the Philippines.</span>
            </span>
          </h1>

          <p
            data-hero="sub"
            className="mt-7 max-w-xl font-sans text-[15px] leading-relaxed text-landing-text-secondary sm:text-lg"
          >
            {COMPANY.legalName} delivers automotive-grade injection-molded parts for
            Toyota, Nissan, Honda, Suzuki, and Yamaha — engineered to IATF 16949 in
            Dasmariñas, Cavite.
          </p>

          <div data-hero="cta" className="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
            <a
              href="#contact"
              className="group inline-flex items-center justify-center gap-2 rounded-full bg-landing-accent px-7 py-4 font-sans text-sm font-semibold text-landing-accent-fg transition-all duration-300 hover:bg-landing-accent-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas"
            >
              Request a quote
              <ArrowRight size={16} className="transition-transform duration-300 group-hover:translate-x-1" />
            </a>
            <a
              href="#capabilities"
              className="inline-flex items-center justify-center gap-2 rounded-full border border-landing-border-strong px-7 py-4 font-sans text-sm font-medium text-landing-text transition-colors duration-300 hover:border-landing-text hover:bg-landing-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas"
            >
              Explore capabilities
            </a>
          </div>

          <ul
            data-hero="trust"
            className="mt-12 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-landing-border pt-6"
          >
            {TRUST.map((item) => (
              <li
                key={item}
                className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.14em] text-landing-muted"
              >
                <span className="h-1 w-1 rounded-full bg-landing-accent" />
                {item}
              </li>
            ))}
          </ul>
        </div>

        {/* ── Drawing frame ─────────────────────────────────────── */}
        <div data-hero="frame" className="relative">
          <figure className="relative aspect-square w-full overflow-hidden rounded-xl border border-landing-border-strong bg-landing-surface">
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

            {/* static section view (base layer + WebGL fallback) */}
            <div className="absolute inset-0 flex items-center justify-center p-12">
              <PartBlueprint className="max-h-[78%] max-w-[78%] opacity-90" />
            </div>

            {/* rotating wireframe part */}
            <HeroCanvas />

            {/* dimension callouts */}
            <span
              data-hero-dim
              className="absolute left-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-accent"
            >
              REV · A
            </span>
            <span
              data-hero-dim
              className="absolute right-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-accent"
            >
              Ø 24.0 ±0.02
            </span>

            {/* title block */}
            <figcaption
              data-hero-dim
              className="absolute inset-x-3 bottom-3 grid grid-cols-3 overflow-hidden rounded-md border border-landing-border bg-landing-canvas/85 font-mono text-[9px] uppercase tracking-[0.12em] text-landing-muted backdrop-blur-sm sm:text-[10px]"
            >
              <span className="border-r border-landing-border px-3 py-2">
                <span className="block text-landing-subtle-text">Part</span>
                <span className="text-landing-text">Wiper bushing</span>
              </span>
              <span className="border-r border-landing-border px-3 py-2">
                <span className="block text-landing-subtle-text">Material</span>
                <span className="text-landing-text">POM resin</span>
              </span>
              <span className="px-3 py-2">
                <span className="block text-landing-subtle-text">Std</span>
                <span className="text-landing-text">IATF 16949</span>
              </span>
            </figcaption>
          </figure>
        </div>
      </div>
    </section>
  );
}
