/**
 * CapabilitiesSection — what Ogami makes and does.
 *
 * Four capability cards in a calm 2-col grid. Each reveals on scroll
 * (staggered). No tilt, no glow — just a clean border lift on hover.
 */

declare const __: unique symbol;

import { cn } from '@/lib/cn';
import { SectionHeading } from '../components/SectionHeading';
import { CAPABILITIES } from '../data';
import type { Capability } from '../data';
import { section, container, card, cardGap, headingGap, monoLabel } from '../styles';

function CapabilityCard({ cap, index }: { cap: Capability; index: number }) {
  const Icon = cap.icon;

  return (
    <article
      data-reveal
      data-reveal-delay={(index * 0.08).toFixed(2)}
      className={card('interactive', 'group')}
    >
      <div className="flex items-start justify-between">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl border border-landing-border bg-landing-elevated text-landing-accent transition-colors duration-300 group-hover:border-landing-accent/40">
          <Icon size={20} strokeWidth={1.6} />
        </div>
        <span className={cn(monoLabel, 'rounded-full border border-landing-border px-3 py-1')}>
          {cap.tag}
        </span>
      </div>

      <h3 className="mt-6 font-display text-xl font-semibold tracking-tight text-landing-text">
        {cap.title}
      </h3>
      <p className="mt-2.5 font-sans text-[14px] leading-relaxed text-landing-text-secondary">
        {cap.blurb}
      </p>
    </article>
  );
}

export function CapabilitiesSection() {
  return (
    <section id="capabilities" className={section('canvas')}>
      <div className={container}>
        <SectionHeading
          eyebrow="Capabilities"
          title={
            <>
              One partner, from raw resin
              <br className="hidden sm:block" /> to finished assembly.
            </>
          }
          intro="Every step of the value chain — tooling, moulding, and assembly — under one roof, under your spec."
        />

        <div className={cn(headingGap, 'grid', cardGap, 'md:grid-cols-2')}>
          {CAPABILITIES.map((cap, i) => (
            <CapabilityCard key={cap.id} cap={cap} index={i} />
          ))}
        </div>
      </div>
    </section>
  );
}
