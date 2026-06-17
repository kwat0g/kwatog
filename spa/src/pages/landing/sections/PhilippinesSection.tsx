/**
 * PhilippinesSection — Filipino identity, carried by words and place.
 *
 * Per the monochrome direction, national identity is textual and grounded:
 * world-class precision engineered by Filipino hands in Dasmariñas, Cavite —
 * no flag, no sun. The visual is a quiet location plate: a blueprint grid, a
 * datum mark at the plant's coordinates, and a precise address readout.
 */

import { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { MapPin } from 'lucide-react';
import { DatumMark } from '../components/DatumMark';
import { COMPANY } from '../data';
import { registerScrollTrigger, reduceMotion } from '../motion';

const POINTS = [
  {
    k: '200+',
    label: 'Skilled Filipino engineers, operators, and quality inspectors',
  },
  {
    k: 'FCIE',
    label: 'First Cavite Industrial Estate — Dasmariñas, Cavite',
  },
  {
    k: '100%',
    label: 'Global automotive standards, delivered locally',
  },
];

export function PhilippinesSection() {
  const figureRef = useRef<HTMLElement>(null);
  const reticleRef = useRef<SVGCircleElement>(null);
  const hLineRef = useRef<HTMLDivElement>(null);
  const vLineRef = useRef<HTMLDivElement>(null);
  const gridRef = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    if (reduceMotion()) return;

    const figure = figureRef.current;
    const reticle = reticleRef.current;
    const hLine = hLineRef.current;
    const vLine = vLineRef.current;
    if (!figure || !reticle || !hLine || !vLine) return;

    registerScrollTrigger();

    const ctx = gsap.context(() => {
      // Slow rotating outer reticle ring — purely decorative
      gsap.to(reticle, {
        rotation: 360,
        transformOrigin: '50% 50%',
        duration: 28,
        ease: 'none',
        repeat: -1,
      });

      // Crosshair draw-in on scroll reveal (once)
      gsap.set(hLine, { scaleX: 0 });
      gsap.set(vLine, { scaleY: 0 });
      gsap.to([hLine, vLine], {
        scaleX: 1,
        scaleY: 1,
        duration: 0.9,
        ease: 'power2.out',
        stagger: 0.1,
        scrollTrigger: {
          trigger: figure,
          start: 'top 80%',
          once: true,
        },
      });

      // Pointer-parallax on the blueprint grid — a few px of depth on mouse
      // move. Fine pointers only: on touch there is no hover, and the rAF would
      // otherwise spin forever animating a ~0px translate.
      const grid = gridRef.current;
      if (!grid || !window.matchMedia('(pointer: fine)').matches) return;

      let rafId = 0;
      let targetX = 0;
      let targetY = 0;
      let currentX = 0;
      let currentY = 0;
      const MAX = 6;

      function onMove(e: PointerEvent) {
        const r = figure!.getBoundingClientRect();
        const nx = (e.clientX - r.left) / r.width - 0.5;
        const ny = (e.clientY - r.top) / r.height - 0.5;
        targetX = nx * MAX;
        targetY = ny * MAX;
      }
      function onLeave() {
        targetX = 0;
        targetY = 0;
      }
      function tick() {
        currentX += (targetX - currentX) * 0.08;
        currentY += (targetY - currentY) * 0.08;
        if (grid) grid.style.transform = `translate(${currentX}px, ${currentY}px)`;
        rafId = requestAnimationFrame(tick);
      }

      figure.addEventListener('pointermove', onMove, { passive: true });
      figure.addEventListener('pointerleave', onLeave, { passive: true });
      rafId = requestAnimationFrame(tick);

      return () => {
        figure.removeEventListener('pointermove', onMove);
        figure.removeEventListener('pointerleave', onLeave);
        cancelAnimationFrame(rafId);
      };
    }, figure);

    return () => ctx.revert();
  }, []);

  return (
    <section
      id="filipino-made"
      className="relative overflow-hidden bg-landing-surface px-5 py-24 sm:px-8 sm:py-32"
    >
      <div className="mx-auto grid max-w-7xl items-center gap-14 lg:grid-cols-2 lg:gap-20">
        {/* Copy */}
        <div data-reveal="left">
          <div data-reveal className="flex items-center gap-3">
            <span className="h-0.5 w-8 bg-landing-accent" />
            <span className="font-mono text-[11px] uppercase tracking-[0.24em] text-landing-accent">
              Filipino-made
            </span>
          </div>

          <h2
            data-reveal
            data-reveal-delay="0.05"
            className="mt-5 font-display text-[clamp(2.1rem,4.8vw,3.75rem)] font-bold leading-[1.04] tracking-[-0.02em] text-landing-text"
          >
            World-class precision,
            <br className="hidden sm:block" /> proudly made at home.
          </h2>

          <p
            data-reveal
            data-reveal-delay="0.1"
            className="mt-5 max-w-xl font-sans text-[15px] leading-relaxed text-landing-text-secondary sm:text-base"
          >
            {COMPANY.legalName} proves that the precision the world&apos;s automakers
            demand can be engineered right here in Cavite. Every part is shaped by
            skilled Filipino hands, held to the same standard trusted on assembly
            lines across the globe.
          </p>

          <dl className="mt-10 space-y-5">
            {POINTS.map((p, i) => (
              <div
                key={p.k}
                data-reveal
                data-reveal-delay={(0.12 + i * 0.06).toFixed(2)}
                className="flex items-baseline gap-5 border-t border-landing-border pt-5"
              >
                <dt className="w-20 shrink-0 font-display text-2xl font-bold tracking-tight text-landing-accent">
                  {p.k}
                </dt>
                <dd className="font-sans text-[14px] leading-relaxed text-landing-text-secondary">
                  {p.label}
                </dd>
              </div>
            ))}
          </dl>
        </div>

        {/* Visual — location plate */}
        <div data-reveal="right" data-reveal-delay="0.1" className="relative">
          <figure
            ref={figureRef}
            className="relative mx-auto aspect-square w-full max-w-md overflow-hidden rounded-xl border border-landing-border-strong bg-landing-canvas"
          >
            {/* blueprint grid — pointer-parallax layer with vertical bleed so edges stay hidden */}
            <div
              ref={gridRef}
              aria-hidden="true"
              className="pointer-events-none absolute top-[-5%] h-[110%] w-full"
              style={{
                backgroundImage:
                  'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
                  'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
                backgroundSize: '32px 32px',
              }}
            />

            {/* crosshair guides — draw in on reveal (scale from center) */}
            <div
              ref={vLineRef}
              aria-hidden="true"
              className="absolute left-1/2 top-0 h-full w-px origin-center -translate-x-1/2 bg-landing-line"
            />
            <div
              ref={hLineRef}
              aria-hidden="true"
              className="absolute left-0 top-1/2 h-px w-full origin-center -translate-y-1/2 bg-landing-line"
            />

            {/* datum + outer reticle ring */}
            <div className="pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2">
              <DatumMark
                size={120}
                strokeWidth={1.1}
                className="text-landing-accent"
              />
              {/* Rotating dashed reticle — GSAP-driven, aria-hidden */}
              <svg
                aria-hidden="true"
                className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
                width={150}
                height={150}
                viewBox="0 0 150 150"
                fill="none"
                stroke="currentColor"
                strokeWidth={0.8}
              >
                <circle
                  ref={reticleRef}
                  cx="75"
                  cy="75"
                  r="68"
                  className="text-landing-accent/25"
                  strokeDasharray="5 9"
                />
              </svg>
            </div>

            {/* location label */}
            <div className="absolute left-1/2 top-[calc(50%+76px)] -translate-x-1/2 whitespace-nowrap text-center">
              <span className="flex items-center justify-center gap-1.5 font-mono text-[11px] uppercase tracking-[0.16em] text-landing-text">
                <MapPin size={13} className="text-landing-accent" />
                Dasmariñas, Cavite
              </span>
            </div>

            {/* coordinate readouts */}
            <span className="absolute left-5 top-5 font-mono text-[10px] uppercase tracking-[0.18em] text-landing-muted">
              14.3294° N
            </span>
            <span className="absolute right-5 top-5 font-mono text-[10px] uppercase tracking-[0.18em] text-landing-muted">
              120.9367° E
            </span>
            <span className="absolute bottom-5 left-5 font-mono text-[10px] uppercase tracking-[0.18em] text-landing-subtle-text">
              Datum · plant origin
            </span>
            <span className="absolute bottom-5 right-5 font-mono text-[10px] uppercase tracking-[0.18em] text-landing-subtle-text">
              Republic of the Philippines
            </span>
          </figure>
        </div>
      </div>
    </section>
  );
}
