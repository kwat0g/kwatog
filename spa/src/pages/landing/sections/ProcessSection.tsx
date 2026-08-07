/**
 * ProcessSection — the resin-to-certified-shipment journey.
 *
 * Desktop: the section pins and the six steps scrub horizontally, with a
 * progress rail tracking position. Mobile (and any reduced-motion context):
 * a clean vertical timeline — panels are never clipped, content always reachable.
 */

import { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ArrowRight } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { SectionHeading } from '../components/SectionHeading';
import { PROCESS_ICONS } from '../data';
import { landingApi } from '@/api/landing';
import { registerScrollTrigger, reduceMotion } from '../motion';
import { cn } from '@/lib/cn';

export function ProcessSection() {
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const processSteps = (content?.process_steps ?? []).map((step) => ({ ...step, icon: PROCESS_ICONS[step.icon] ?? PROCESS_ICONS.layers }));
  const pinRef = useRef<HTMLDivElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const innerRef = useRef<HTMLDivElement>(null);
  const fillRef = useRef<HTMLDivElement>(null);
  const readoutRef = useRef<HTMLSpanElement>(null);

  // Horizontal scrub only when motion is allowed; otherwise vertical stack.
  const horizontal = !reduceMotion();

  useLayoutEffect(() => {
    if (!horizontal) return;
    registerScrollTrigger();

    const mm = gsap.matchMedia();
    mm.add('(min-width: 1024px)', () => {
      const inner = innerRef.current;
      const container = containerRef.current;
      const pin = pinRef.current;
      const fill = fillRef.current;
      const readout = readoutRef.current;
      if (!inner || !container || !pin) return;

      const total = processSteps.length;
      const pad = (n: number) => String(n + 1).padStart(2, '0');

      // Seed initial state
      if (readout) readout.textContent = `STEP 01 / ${pad(total - 1)}`;

      // Get card elements after mount
      const cards = Array.from(inner.querySelectorAll<HTMLElement>('article[data-step-index]'));

      let lastIndex = -1;

      const distance = () => Math.max(0, inner.scrollWidth - container.clientWidth);

      const tween = gsap.to(inner, {
        x: () => -distance(),
        ease: 'none',
        scrollTrigger: {
          trigger: pin,
          start: 'top top',
          end: () => '+=' + distance(),
          pin: true,
          scrub: 0.6,
          anticipatePin: 1,
          invalidateOnRefresh: true,
          onUpdate: (self) => {
            if (fill) fill.style.transform = `scaleX(${self.progress})`;

            const activeIndex = Math.min(
              Math.floor(self.progress * total),
              total - 1,
            );

            if (readout) {
              readout.textContent = `STEP ${pad(activeIndex)} / ${pad(total - 1)}`;
            }

            if (activeIndex !== lastIndex) {
              lastIndex = activeIndex;
              cards.forEach((card, i) => {
                card.dataset.active = i === activeIndex ? 'true' : 'false';
              });
            }
          },
        },
      });

      return () => {
        tween.scrollTrigger?.kill();
        tween.kill();
        gsap.set(inner, { clearProps: 'x' });
        // Reset card active states
        cards.forEach((card) => {
          card.dataset.active = 'false';
        });
      };
    });

    return () => mm.revert();
  }, [horizontal, processSteps.length]);

  return (
    <section id="process" className="relative bg-surface">
      <div
        ref={pinRef}
        className={cn(horizontal && 'lg:flex lg:h-screen lg:flex-col lg:justify-center')}
      >
        <div className="px-5 sm:px-5 pt-20 sm:pt-28 lg:pt-0">
          <SectionHeading
            eyebrow={content?.section_copy?.process_eyebrow || 'Manufacturing Journey'}
            title={content?.section_copy?.process_title || 'From Raw Resin to Certified Shipment'}
            intro={content?.section_copy?.process_intro || 'Six automated, traceable stages ensuring zero-defect quality and full lot lineage for every component.'}
          />

          {/* Progress rail + step readout + scroll hint (desktop) */}
          {horizontal && (
            <div className="mt-8 hidden items-center gap-4 lg:flex">
              <div className="h-px w-full max-w-xs overflow-hidden bg-border-default">
                <div
                  ref={fillRef}
                  className="h-full origin-left scale-x-0 bg-accent"
                />
              </div>
              <span
                ref={readoutRef}
                className="w-[7.5ch] shrink-0 font-mono text-[10px] tabular-nums tracking-[0.18em] text-accent"
              >
                STEP 01 / 06
              </span>
              <span className="motion-safe:animate-pulse flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-[0.18em] text-muted">
                Scroll to explore
                <ArrowRight size={12} />
              </span>
            </div>
          )}
        </div>

        <div
          ref={containerRef}
          className={cn('mt-12 lg:mt-10', horizontal && 'lg:overflow-hidden')}
        >
          <div
            ref={innerRef}
            className={cn(
              'flex flex-col gap-5 px-5 pb-24 sm:px-5',
              horizontal &&
                'lg:flex-row lg:items-stretch lg:gap-7 lg:pb-0 lg:pr-[12vw]',
            )}
          >
            {processSteps.map((step) => {
              const Icon = step.icon;
              return (
                <article
                  key={step.index}
                  data-reveal={!horizontal ? '' : undefined}
                  data-step-index={step.index}
                  data-active="false"
                  className={cn(
                    'group relative flex flex-col rounded-md border border-default bg-elevated p-5 sm:p-7 transition-colors duration-500',
                    'hover:border-accent/40',
                    // Active state: top accent rule + stronger border
                    'data-[active=true]:border-accent/60',
                    horizontal && 'lg:w-[clamp(300px,30vw,400px)] lg:shrink-0',
                  )}
                >
                  {/* Top accent rule — only visible when active (reads group's data-active) */}
                  <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-0 top-0 h-px origin-left scale-x-0 rounded-t-xl bg-accent transition-transform duration-500 group-data-[active=true]:scale-x-100"
                    style={{ transitionProperty: 'transform' }}
                  />

                  <div className="flex items-center justify-between">
                    <span
                      className={cn(
                        'font-mono text-6xl font-medium tabular-nums transition-all duration-500',
                        'text-accent/55 group-hover:text-accent',
                        // Full accent when card is active
                        'group-data-[active=true]:text-accent',
                      )}
                    >
                      {step.index}
                    </span>
                    <div className="flex h-11 w-11 items-center justify-center rounded-md border border-default text-secondary transition-colors duration-500 group-hover:border-accent/40 group-hover:text-accent group-data-[active=true]:border-accent/40 group-data-[active=true]:text-accent">
                      <Icon size={20} strokeWidth={1.6} />
                    </div>
                  </div>

                  <h3 className="mt-10 font-display text-2xl font-semibold tracking-[-0.02em] text-primary">
                    {step.title}
                  </h3>
                  <p className="mt-4 font-sans text-base font-light leading-relaxed text-secondary">
                    {step.body}
                  </p>
                </article>
              );
            })}
          </div>
        </div>
      </div>
    </section>
  );
}
