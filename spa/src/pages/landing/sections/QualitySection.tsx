/**
 * QualitySection — IATF 16949 woven across the chain, framed as buyer guarantees.
 *
 * Four quality touchpoints (incoming → in-process → outgoing → CoC) as cards,
 * a strip of the formal methods, and a closing assurance line. The whole point:
 * a customer can trust what ships because it was checked at every stage.
 */

import { ShieldCheck, Download, Award, type LucideIcon } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { SectionHeading } from '../components/SectionHeading';
import { QUALITY_PILLAR_ICONS } from '../data';
import { landingApi } from '@/api/landing';
import { focusRingLanding } from '@/lib/focus';
import { section, container, cardGap } from '../styles';
import { cn } from '@/lib/cn';

type PillarData = { id: string; title: string; body: string; icon: LucideIcon };

function PillarCell({ pillar, index }: { pillar: PillarData; index: number }) {
  const Icon = pillar.icon;
  return (
    <div
      data-reveal
      data-reveal-delay={(index * 0.08).toFixed(2)}
      className="group relative flex flex-col rounded-lg border border-default bg-surface p-6 transition-colors duration-normal hover:border-accent/50 sm:p-8"
    >
      <span className="font-mono text-[11px] tabular-nums text-text-subtle">
        0{index + 1}
      </span>
      <div className="mt-5 flex h-11 w-11 items-center justify-center rounded-md border border-default text-accent transition-colors duration-500 group-hover:border-accent/40">
        <Icon size={20} strokeWidth={1.6} />
      </div>
      <h3 className="mt-6 font-display text-xl font-semibold tracking-[-0.02em] text-primary">
        {pillar.title}
      </h3>
      <p className="mt-3 font-sans text-[15px] font-light leading-relaxed text-secondary">
        {pillar.body}
      </p>
    </div>
  );
}

export function QualitySection() {
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const qualityMethods = content?.quality_methods ?? [];
  const qualityPolicy = content?.quality_policy;
  const qualityStandard = qualityPolicy?.standard ?? qualityMethods[0] ?? '—';
  const qualityPillars: PillarData[] = (content?.quality_pillars ?? []).map((pillar) => ({ ...pillar, icon: QUALITY_PILLAR_ICONS[pillar.icon] ?? QUALITY_PILLAR_ICONS.ruler }));

  return (
    <section id="quality" className={section('canvas')}>
      <div className={container}>
        <div className="flex flex-col gap-10 lg:flex-row lg:items-end lg:justify-between">
          <SectionHeading
            eyebrow={`Quality · ${qualityStandard}`}
            title={content?.section_copy?.quality_title ?? '—'}
            intro={content?.section_copy?.quality_intro ?? '—'}
          />

          <div className="flex flex-wrap gap-2 lg:max-w-xs lg:justify-end">
            {qualityMethods.map((m, i) => (
              <span
                key={m}
                data-reveal
                data-reveal-delay={(i * 0.07).toFixed(2)}
                className="rounded-full border border-default bg-surface px-3.5 py-1.5 font-mono text-[11px] uppercase tracking-[0.12em] text-secondary"
              >
                {m}
              </span>
            ))}
          </div>
        </div>

        <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          {qualityPillars.map((pillar, i) => (
            <PillarCell key={pillar.id} pillar={pillar} index={i} />
          ))}
        </div>

        <div className={`mt-6 grid ${cardGap} lg:grid-cols-[1fr_1.4fr]`}>
          <div
            data-reveal="scale"
            className="flex flex-col justify-between rounded-lg border border-default bg-surface p-6 transition-colors duration-normal hover:border-accent/50 sm:p-8"
          >
            <div className="flex h-12 w-12 items-center justify-center rounded-md border border-default text-accent">
              <Award size={22} strokeWidth={1.6} />
            </div>
            <div className="mt-5">
              <h3 className="font-display text-2xl font-semibold tracking-[-0.02em] text-primary">
                {qualityPolicy?.certification_title ?? '—'}
              </h3>
              <p className="mt-3 font-sans text-base font-light leading-relaxed text-secondary">
                {qualityPolicy?.certification_body ?? '—'}
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
                className={cn('mt-6 inline-flex w-fit items-center gap-2 rounded-full border border-strong px-6 py-3 font-sans text-[14px] font-medium text-primary transition-all duration-300 hover:scale-105 hover:border-primary hover:bg-elevated cursor-pointer', focusRingLanding)}
              >
                <Download size={14} />
                Download quality policy
              </button>
            </div>
          </div>

          <div
            data-reveal="scale"
            data-reveal-delay="0.08"
            className="flex items-start gap-5 rounded-lg border border-accent/30 bg-accent/5 p-6 transition-colors duration-normal hover:border-accent/50 sm:p-8"
          >
            <ShieldCheck size={26} className="mt-0.5 shrink-0 text-accent" strokeWidth={2} />
            <p className="font-sans text-base font-light leading-relaxed text-secondary">
                <span className="font-medium text-primary">
                  {qualityPolicy?.conformance_title ?? '—'}
                </span>{' '}
                {qualityPolicy?.conformance_body ?? '—'}
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
