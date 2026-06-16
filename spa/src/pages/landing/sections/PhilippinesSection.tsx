/**
 * PhilippinesSection — Filipino identity, carried by words and place.
 *
 * Per the monochrome direction, national identity is textual and grounded:
 * world-class precision engineered by Filipino hands in Dasmariñas, Cavite —
 * no flag, no sun. The visual is a quiet location plate: a blueprint grid, a
 * datum mark at the plant's coordinates, and a precise address readout.
 */

import { MapPin } from 'lucide-react';
import { DatumMark } from '../components/DatumMark';
import { COMPANY } from '../data';

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
  return (
    <section
      id="filipino-made"
      className="relative overflow-hidden bg-landing-surface px-5 py-24 sm:px-8 sm:py-32"
    >
      <div className="mx-auto grid max-w-7xl items-center gap-14 lg:grid-cols-2 lg:gap-20">
        {/* Copy */}
        <div>
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
        <div data-reveal data-reveal-delay="0.1" className="relative">
          <figure className="relative mx-auto aspect-square w-full max-w-md overflow-hidden rounded-xl border border-landing-border-strong bg-landing-canvas">
            {/* blueprint grid */}
            <div
              aria-hidden="true"
              className="absolute inset-0"
              style={{
                backgroundImage:
                  'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
                  'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
                backgroundSize: '32px 32px',
              }}
            />

            {/* crosshair guides through the datum */}
            <div aria-hidden="true" className="absolute left-1/2 top-0 h-full w-px -translate-x-1/2 bg-landing-line" />
            <div aria-hidden="true" className="absolute left-0 top-1/2 h-px w-full -translate-y-1/2 bg-landing-line" />

            {/* datum at the plant's position */}
            <DatumMark
              size={120}
              strokeWidth={1.1}
              className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-landing-accent"
            />

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
