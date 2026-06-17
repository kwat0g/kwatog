/**
 * StatsSection — proof in numbers.
 *
 * Each figure counts up once as it scrolls into view (ScrollTrigger-gated),
 * written straight to the DOM node for smoothness. Reduced-motion users get the
 * final value with no animation.
 *
 * A dimension underline (engineering measurement line with end-ticks) draws in
 * sync with each count-up via the same ScrollTrigger onEnter.
 */

import { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { STATS, type StatItem } from '../data';
import { registerScrollTrigger, reduceMotion } from '../motion';
import { section, container } from '../styles';

function formatValue(n: number, stat: StatItem): string {
  const fixed = n.toFixed(stat.decimals ?? 0);
  const [int, dec] = fixed.split('.');
  const withSep = Number(int).toLocaleString('en-US');
  const num = dec ? `${withSep}.${dec}` : withSep;
  return `${stat.prefix ?? ''}${num}${stat.suffix ?? ''}`;
}

function Counter({ stat }: { stat: StatItem }) {
  const ref = useRef<HTMLSpanElement>(null);
  const lineRef = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    const line = lineRef.current;
    if (!el) return;

    if (reduceMotion()) {
      el.textContent = formatValue(stat.value, stat);
      if (line) line.style.transform = 'scaleX(1)';
      return;
    }

    registerScrollTrigger();
    el.textContent = formatValue(0, stat);
    if (line) line.style.transform = 'scaleX(0)';
    const obj = { v: 0 };

    const st = ScrollTrigger.create({
      trigger: el,
      start: 'top 90%',
      once: true,
      onEnter: () => {
        gsap.to(obj, {
          v: stat.value,
          duration: 1.6,
          ease: 'power2.out',
          onUpdate: () => {
            el.textContent = formatValue(obj.v, stat);
          },
        });
        if (line) {
          gsap.to(line, {
            scaleX: 1,
            duration: 1.6,
            ease: 'power2.out',
          });
        }
      },
    });

    return () => st.kill();
  }, [stat]);

  return (
    <span className="relative inline-block">
      <span ref={ref} className="tabular-nums" />
      {/* Engineering dimension underline with end-ticks */}
      <span aria-hidden="true" className="pointer-events-none absolute inset-x-0 bottom-[-6px] flex items-center">
        {/* left tick */}
        <span
          className="h-[5px] w-px shrink-0"
          style={{ background: 'var(--landing-accent)', opacity: 0.55 }}
        />
        {/* horizontal rule */}
        <span
          ref={lineRef}
          className="h-px flex-1 origin-left"
          style={{
            background: 'var(--landing-accent)',
            opacity: 0.55,
            transform: 'scaleX(0)',
          }}
        />
        {/* right tick */}
        <span
          className="h-[5px] w-px shrink-0"
          style={{ background: 'var(--landing-accent)', opacity: 0.55 }}
        />
      </span>
    </span>
  );
}

export function StatsSection() {
  return (
    <section className={section('surface')}>
      <div className={`${container} grid gap-x-6 gap-y-12 sm:grid-cols-2 lg:grid-cols-4`}>
        {STATS.map((stat, i) => (
          <div
            key={stat.id}
            data-reveal
            data-reveal-delay={(i * 0.08).toFixed(2)}
            className="border-l border-landing-border pl-6"
          >
            <div className="font-display text-[clamp(2.75rem,6vw,4rem)] font-bold leading-none tracking-tight text-landing-text">
              <Counter stat={stat} />
            </div>
            <p className="mt-3 font-mono text-[11px] uppercase tracking-[0.16em] text-landing-muted">
              {stat.label}
            </p>
          </div>
        ))}
      </div>
    </section>
  );
}
