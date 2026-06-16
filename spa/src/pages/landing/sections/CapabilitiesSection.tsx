/**
 * CapabilitiesSection — what Ogami makes and does.
 *
 * Four capability cards on a light paper grid. Each reveals on scroll
 * (staggered), and lifts with an espresso edge + corner glow on hover. Framed for
 * buyers: what they can source, and why the in-house control matters.
 */

import { SectionHeading } from '../components/SectionHeading';
import { CAPABILITIES } from '../data';

export function CapabilitiesSection() {
  return (
    <section id="capabilities" className="relative bg-landing-canvas px-5 py-24 sm:px-8 sm:py-32">
      <div className="mx-auto max-w-7xl">
        <SectionHeading
          eyebrow="Capabilities"
          title={
            <>
              One partner, from raw resin
              <br className="hidden sm:block" /> to finished assembly.
            </>
          }
          intro="Ogami controls every step of the value chain in-house — so quality, lead time, and tooling stay under one roof, and under your spec."
        />

        <div className="mt-16 grid gap-5 md:grid-cols-2">
          {CAPABILITIES.map((cap, i) => {
            const Icon = cap.icon;
            return (
              <article
                key={cap.id}
                data-reveal
                data-reveal-delay={(i * 0.08).toFixed(2)}
                className="group relative overflow-hidden rounded-2xl border border-landing-border bg-landing-surface p-7 transition-all duration-500 hover:-translate-y-1 hover:border-landing-accent/40 sm:p-9"
              >
                {/* corner glow */}
                <div className="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-landing-accent-glow opacity-0 blur-3xl transition-opacity duration-500 group-hover:opacity-100" />

                <div className="relative flex items-start justify-between">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-landing-border bg-landing-elevated text-landing-accent transition-colors duration-500 group-hover:border-landing-accent/40">
                    <Icon size={22} strokeWidth={1.6} />
                  </div>
                  <span className="rounded-full border border-landing-border px-3 py-1 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-muted">
                    {cap.tag}
                  </span>
                </div>

                <h3 className="relative mt-7 font-display text-2xl font-semibold tracking-tight text-landing-text">
                  {cap.title}
                </h3>
                <p className="relative mt-3 max-w-md font-sans text-[14px] leading-relaxed text-landing-text-secondary">
                  {cap.blurb}
                </p>

                <ul className="relative mt-6 flex flex-wrap gap-2">
                  {cap.points.map((pt) => (
                    <li
                      key={pt}
                      className="flex items-center gap-2 rounded-lg bg-landing-elevated px-3 py-1.5 font-mono text-[11px] text-landing-text-secondary"
                    >
                      <span className="h-1 w-1 rounded-full bg-landing-accent" />
                      {pt}
                    </li>
                  ))}
                </ul>
              </article>
            );
          })}
        </div>
      </div>
    </section>
  );
}
