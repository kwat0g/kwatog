/**
 * QualitySection — IATF 16949 woven across the chain, framed as buyer guarantees.
 *
 * Four quality touchpoints (incoming → in-process → outgoing → CoC) as cards,
 * a strip of the formal methods, and a closing assurance line. The whole point:
 * a customer can trust what ships because it was checked at every stage.
 */

import { ShieldCheck, Download, Award } from 'lucide-react';
import { SectionHeading } from '../components/SectionHeading';
import { QUALITY_PILLARS, QUALITY_METHODS } from '../data';
import { landingApi } from '@/api/landing';
import { section, container, cardGap } from '../styles';

type PillarData = (typeof QUALITY_PILLARS)[number];

function PillarCell({ pillar, index }: { pillar: PillarData; index: number }) {
  const Icon = pillar.icon;
  return (
    <div
      data-reveal
      data-reveal-delay={(index * 0.08).toFixed(2)}
      className="group relative flex flex-col bg-landing-surface p-6 transition-colors duration-500 hover:bg-landing-elevated sm:p-7"
    >
      <span className="font-mono text-[11px] tabular-nums text-landing-subtle-text">
        0{index + 1}
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
}

export function QualitySection() {
  return (
    <section id="quality" className={section('canvas')}>
      <div className={container}>
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

          <div className="flex flex-wrap gap-2 lg:max-w-xs lg:justify-end">
            {QUALITY_METHODS.map((m, i) => (
              <span
                key={m}
                data-reveal
                data-reveal-delay={(i * 0.07).toFixed(2)}
                className="rounded-full border border-landing-border bg-landing-surface px-3.5 py-1.5 font-mono text-[11px] uppercase tracking-[0.12em] text-landing-text-secondary"
              >
                {m}
              </span>
            ))}
          </div>
        </div>

        <div className="mt-16 grid gap-px overflow-hidden rounded-xl border border-landing-border bg-landing-border sm:grid-cols-2 lg:grid-cols-4">
          {QUALITY_PILLARS.map((pillar, i) => (
            <PillarCell key={pillar.id} pillar={pillar} index={i} />
          ))}
        </div>

        <div className={`mt-6 grid ${cardGap} lg:grid-cols-[1fr_1.4fr]`}>
          <div
            data-reveal="scale"
            className="flex flex-col justify-between rounded-xl border border-landing-border bg-landing-surface p-6 sm:p-7"
          >
            <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-landing-border text-landing-accent">
              <Award size={22} strokeWidth={1.6} />
            </div>
            <div className="mt-5">
              <h3 className="font-display text-lg font-semibold tracking-tight text-landing-text">
                IATF 16949:2016 Certified
              </h3>
              <p className="mt-2 text-[13px] leading-relaxed text-landing-text-secondary">
                Our quality management system is certified for automotive production —
                audited, maintained, and continuously improved.
              </p>
              <button
                type="button"
                onClick={() => {
                  landingApi
                    .downloadQualityPolicy()
                    .then((blob) => {
                      const url = window.URL.createObjectURL(blob);
                      const a = document.createElement('a');
                      a.href = url;
                      a.download = 'ogami-quality-policy.pdf';
                      document.body.appendChild(a);
                      a.click();
                      a.remove();
                      window.URL.revokeObjectURL(url);
                    })
                    .catch(() => {
                      // Error toast is handled by the global axios interceptor.
                    });
                }}
                className="mt-5 inline-flex items-center gap-2 rounded-full border border-landing-border px-4 py-2 font-sans text-[12px] font-medium text-landing-text transition-colors hover:border-landing-accent/40 hover:bg-landing-elevated"
              >
                <Download size={14} />
                Download quality policy
              </button>
            </div>
          </div>

          <div
            data-reveal="scale"
            data-reveal-delay="0.08"
            className="flex items-start gap-4 rounded-xl border border-landing-accent/20 bg-landing-accent-glow p-6 sm:p-7"
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
      </div>
    </section>
  );
}
