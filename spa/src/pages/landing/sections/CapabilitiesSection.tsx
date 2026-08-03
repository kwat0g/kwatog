/**
 * CapabilitiesSection — what Ogami makes and does.
 *
 * Four capability cards in a calm 2-col grid. Each reveals on scroll
 * (staggered). No tilt, no glow — just a clean border lift on hover.
 */

import { cn } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { landingApi } from '@/api/landing';
import { SectionHeading } from '../components/SectionHeading';
import { CAPABILITY_ICONS } from '../data';
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
        <div className="flex h-11 w-11 items-center justify-center rounded-md border border-landing-border bg-landing-elevated text-landing-accent transition-colors duration-300 group-hover:border-landing-accent/40">
          <Icon size={20} strokeWidth={1.6} />
        </div>
        <span className={cn(monoLabel, 'rounded-full border border-landing-border px-3 py-1')}>
          {cap.tag}
        </span>
      </div>

      <h3 className="mt-6 font-display text-xl font-medium tracking-tight text-landing-text">
        {cap.title}
      </h3>
      <p className="mt-2.5 font-sans text-[14px] leading-relaxed text-landing-text-secondary">
        {cap.blurb}
      </p>
    </article>
  );
}

export function CapabilitiesSection() {
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const capabilities: Capability[] = (content?.capabilities ?? []).map((cap) => ({ ...cap, icon: CAPABILITY_ICONS[cap.icon] ?? CAPABILITY_ICONS.assembly }));
  return (
    <section id="capabilities" className={section('canvas')}>
      <div className={container}>
        <SectionHeading
          eyebrow={content?.section_copy?.capabilities_eyebrow || 'Capabilities'}
          title={content?.section_copy?.capabilities_title || 'Precision Manufacturing & Engineering'}
          intro={content?.section_copy?.capabilities_intro || 'End-to-end injection molding, precision tooling, cleanroom assembly, and metrology inspection for demanding automotive specs.'}
        />

        <div className={cn(headingGap, 'grid', cardGap, 'md:grid-cols-2')}>
          {capabilities.map((cap, i) => (
            <CapabilityCard key={cap.id} cap={cap} index={i} />
          ))}
        </div>
      </div>
    </section>
  );
}
