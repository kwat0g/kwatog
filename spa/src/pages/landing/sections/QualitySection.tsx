/**
 * QualitySection — IATF 16949 woven across the chain, framed as buyer guarantees.
 *
 * Four quality touchpoints (incoming → in-process → outgoing → CoC) as cards,
 * a strip of the formal methods, and a closing assurance line. The whole point:
 * a customer can trust what ships because it was checked at every stage.
 */

import { ShieldCheck } from 'lucide-react';
import { SectionHeading } from '../components/SectionHeading';
import { QUALITY_PILLARS, QUALITY_METHODS } from '../data';

export function QualitySection() {
  return (
    <section id="quality" className="relative bg-landing-canvas px-5 py-24 sm:px-8 sm:py-32">
      <div className="mx-auto max-w-7xl">
        <div className="flex flex-col gap-10 lg:flex-row lg:items-end lg:justify-between">
          <SectionHeading
            eyebrow="Quality · IATF 16949"
            title={
              <>
                Quality you can audit,
                <br className="hidden sm:block" /> on every shipment.
              </>
            }
            intro="Quality is not a department at Ogami — it is built into the chain. Four checkpoints stand between raw resin and your receiving dock."
          />

          <div
            data-reveal
            className="flex flex-wrap gap-2 lg:max-w-xs lg:justify-end"
          >
            {QUALITY_METHODS.map((m) => (
              <span
                key={m}
                className="rounded-full border border-landing-border bg-landing-surface px-3.5 py-1.5 font-mono text-[11px] uppercase tracking-[0.12em] text-landing-text-secondary"
              >
                {m}
              </span>
            ))}
          </div>
        </div>

        <div className="mt-16 grid gap-px overflow-hidden rounded-2xl border border-landing-border bg-landing-border sm:grid-cols-2 lg:grid-cols-4">
          {QUALITY_PILLARS.map((pillar, i) => {
            const Icon = pillar.icon;
            return (
              <div
                key={pillar.id}
                data-reveal
                data-reveal-delay={(i * 0.08).toFixed(2)}
                className="group relative flex flex-col bg-landing-surface p-7 transition-colors duration-500 hover:bg-landing-elevated sm:p-8"
              >
                <span className="font-mono text-[11px] tabular-nums text-landing-subtle-text">
                  0{i + 1}
                </span>
                <div className="mt-5 flex h-11 w-11 items-center justify-center rounded-xl border border-landing-border text-landing-accent transition-colors duration-500 group-hover:border-landing-accent/40">
                  <Icon size={20} strokeWidth={1.6} />
                </div>
                <h3 className="mt-5 font-display text-lg font-semibold tracking-tight text-landing-text">
                  {pillar.title}
                </h3>
                <p className="mt-2.5 font-sans text-[13px] leading-relaxed text-landing-text-secondary">
                  {pillar.body}
                </p>
              </div>
            );
          })}
        </div>

        <div
          data-reveal
          className="mt-6 flex items-start gap-4 rounded-2xl border border-landing-accent/20 bg-landing-accent-glow px-7 py-6"
        >
          <ShieldCheck size={22} className="mt-0.5 shrink-0 text-landing-accent" strokeWidth={1.7} />
          <p className="font-sans text-[14px] leading-relaxed text-landing-text-secondary">
            <span className="font-medium text-landing-text">
              Every shipment ships with a Certificate of Conformance
            </span>{' '}
            built from real inspection data — outgoing parts are sampled at AQL 0.65
            Level II and measured against your critical-dimension tolerances, with
            full traceability from resin lot to delivery.
          </p>
        </div>
      </div>
    </section>
  );
}
