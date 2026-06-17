import { Car, Stethoscope, Layers, Hammer } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { SectionHeading } from '../components/SectionHeading';
import { useTilt } from '../hooks/useTilt';

interface Part {
  id: string;
  title: string;
  icon: LucideIcon;
  example: string;
  material: string;
  tolerance: string;
  note: string;
}

const PARTS: Part[] = [
  {
    id: 'automotive',
    title: 'Automotive resin parts',
    icon: Car,
    example: 'Wiper bushing',
    material: 'POM resin',
    tolerance: '±0.02 mm',
    note: 'Supplied tier-direct to global OEMs.',
  },
  {
    id: 'medical',
    title: 'Medical & precision parts',
    icon: Stethoscope,
    example: 'Light-electric resin part',
    material: 'Engineering-grade resin',
    tolerance: 'Tight tolerance',
    note: 'Lot traceability on every shot.',
  },
  {
    id: 'assembly',
    title: 'Assembly & sub-assembly',
    icon: Layers,
    example: 'Kitted sub-assembly',
    material: 'Multi-component',
    tolerance: 'In-line inspected',
    note: 'Arrives ready for your line.',
  },
  {
    id: 'tooling',
    title: 'In-house mold & tooling',
    icon: Hammer,
    example: 'Precision mold core',
    material: 'Tool steel / aluminum',
    tolerance: 'Built in-house',
    note: 'Protects your IP and lead time.',
  },
];

function PartCard({ part, index }: { part: Part; index: number }) {
  const tiltRef = useTilt<HTMLElement>({ max: 8, lift: 18 });
  const Icon = part.icon;

  return (
    <article
      ref={tiltRef}
      data-reveal
      data-reveal-delay={(index * 0.08).toFixed(2)}
      className="group relative overflow-hidden rounded-2xl border border-landing-border bg-landing-canvas p-6 transition-colors duration-500 hover:border-landing-accent/40"
    >
      {/* tilt spotlight */}
      <div
        data-tilt-glow
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 rounded-2xl opacity-0 transition-opacity duration-300"
        style={{ background: 'radial-gradient(240px circle at var(--mx) var(--my), var(--landing-accent-glow), transparent 65%)' }}
      />

      <div
        data-tilt-lift
        style={{ transformStyle: 'preserve-3d' }}
        className="relative"
      >
        <div className="flex h-11 w-11 items-center justify-center rounded-xl border border-landing-border bg-landing-elevated text-landing-accent transition-colors duration-500 group-hover:border-landing-accent/40">
          <Icon size={20} strokeWidth={1.6} />
        </div>
        <h3 className="mt-5 font-display text-lg font-semibold tracking-tight text-landing-text">
          {part.title}
        </h3>
        <p className="mt-2 text-[13px] text-landing-text-secondary">{part.note}</p>
        <div className="mt-5 space-y-1.5 border-t border-landing-border pt-4">
          <p className="font-mono text-[10px] uppercase tracking-[0.14em] text-landing-subtle-text">
            Example
          </p>
          <p className="text-[13px] font-medium text-landing-text">{part.example}</p>
        </div>
        <div className="mt-3 flex items-center justify-between font-mono text-[10px] uppercase tracking-[0.12em] text-landing-muted">
          <span>{part.material}</span>
          <span className="text-landing-accent">{part.tolerance}</span>
        </div>
      </div>
    </article>
  );
}

export function PartsGallerySection() {
  return (
    <section id="parts" className="relative bg-landing-surface px-5 py-24 sm:px-8 sm:py-32">
      <div className="mx-auto max-w-7xl">
        <SectionHeading
          eyebrow="Parts we make"
          title={
            <>
              Precision parts,
              <br className="hidden sm:block" /> shipped to spec.
            </>
          }
          intro="From a single bushing to complex sub-assemblies, every part is molded, checked, and released under one roof."
        />

        <div className="mt-16 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {PARTS.map((part, i) => (
            <PartCard key={part.id} part={part} index={i} />
          ))}
        </div>
      </div>
    </section>
  );
}
