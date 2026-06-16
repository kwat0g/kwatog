/**
 * StatsSection — proof in numbers.
 *
 * Each figure counts up once as it scrolls into view (ScrollTrigger-gated),
 * written straight to the DOM node for smoothness. Reduced-motion users get the
 * final value with no animation.
 */

import { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { STATS, type StatItem } from '../data';
import { registerScrollTrigger, reduceMotion } from '../motion';

function formatValue(n: number, stat: StatItem): string {
  const fixed = n.toFixed(stat.decimals ?? 0);
  const [int, dec] = fixed.split('.');
  const withSep = Number(int).toLocaleString('en-US');
  const num = dec ? `${withSep}.${dec}` : withSep;
  return `${stat.prefix ?? ''}${num}${stat.suffix ?? ''}`;
}

function Counter({ stat }: { stat: StatItem }) {
  const ref = useRef<HTMLSpanElement>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;

    if (reduceMotion()) {
      el.textContent = formatValue(stat.value, stat);
      return;
    }

    registerScrollTrigger();
    el.textContent = formatValue(0, stat);
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
      },
    });

    return () => st.kill();
  }, [stat]);

  return <span ref={ref} className="tabular-nums" />;
}

export function StatsSection() {
  return (
    <section className="relative border-y border-landing-border bg-landing-canvas px-5 py-20 sm:px-8 sm:py-24">
      <div className="mx-auto grid max-w-7xl gap-x-6 gap-y-12 sm:grid-cols-2 lg:grid-cols-4">
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
