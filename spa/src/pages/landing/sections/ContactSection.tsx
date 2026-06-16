/**
 * ContactSection — the closing call to action.
 *
 * A single, confident invitation to start a part with Ogami. Customer-facing
 * only (no ERP language): quote request and a direct line, plus the company
 * coordinates. Anchored by a soft warm wash, a blueprint grid, and a datum
 * mark motif.
 */

import { ArrowRight, Mail, Phone } from 'lucide-react';
import { DatumMark } from '../components/DatumMark';
import { COMPANY } from '../data';

export function ContactSection() {
  return (
    <section id="contact" className="relative bg-landing-canvas px-5 py-24 sm:px-8 sm:py-32">
      <div className="mx-auto max-w-7xl">
        <div className="relative overflow-hidden rounded-[2rem] border border-landing-border-strong bg-landing-surface px-7 py-16 sm:px-14 sm:py-20">
          {/* atmosphere — soft warm wash + blueprint grid */}
          <div
            aria-hidden="true"
            className="absolute inset-0"
            style={{
              background:
                'radial-gradient(90% 110% at 100% 0%, rgba(28,25,23,0.05) 0%, rgba(250,250,249,0) 60%),' +
                'radial-gradient(90% 100% at 0% 100%, rgba(28,25,23,0.04) 0%, rgba(250,250,249,0) 60%)',
            }}
          />
          <div
            aria-hidden="true"
            className="absolute inset-0 opacity-70"
            style={{
              backgroundImage:
                'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
                'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
              backgroundSize: '32px 32px',
              maskImage: 'radial-gradient(120% 100% at 90% 10%, #000 30%, transparent 80%)',
              WebkitMaskImage: 'radial-gradient(120% 100% at 90% 10%, #000 30%, transparent 80%)',
            }}
          />
          <DatumMark
            size={300}
            solidCore={false}
            strokeWidth={0.4}
            className="pointer-events-none absolute -bottom-20 -right-16 text-landing-accent/[0.06] motion-safe:animate-[spin_120s_linear_infinite]"
          />

          <div className="relative max-w-2xl">
            <p
              data-reveal
              className="font-mono text-[11px] uppercase tracking-[0.24em] text-landing-accent"
            >
              Let&apos;s build it
            </p>
            <h2
              data-reveal
              data-reveal-delay="0.05"
              className="mt-5 font-display text-[clamp(2.25rem,5.5vw,4rem)] font-bold leading-[1.02] tracking-[-0.025em] text-landing-text"
            >
              Have a part in mind? Let&apos;s mold it.
            </h2>
            <p
              data-reveal
              data-reveal-delay="0.1"
              className="mt-5 font-sans text-[15px] leading-relaxed text-landing-text-secondary sm:text-lg"
            >
              Send us your drawing or your challenge. Our engineers will come back
              with tooling, tolerance, and timeline — and a clear path to your first
              certified shipment.
            </p>

            <div
              data-reveal
              data-reveal-delay="0.15"
              className="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center"
            >
              <a
                href={`mailto:${COMPANY.email}?subject=Quote%20request`}
                className="group inline-flex items-center justify-center gap-2 rounded-full bg-landing-accent px-7 py-4 font-sans text-sm font-semibold text-landing-accent-fg transition-all duration-300 hover:bg-landing-accent-hover hover:shadow-[0_8px_30px_-8px_rgba(28,25,23,0.4)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-surface"
              >
                Request a quote
                <ArrowRight
                  size={16}
                  className="transition-transform duration-300 group-hover:translate-x-1"
                />
              </a>
              <a
                href={`mailto:${COMPANY.email}`}
                className="inline-flex items-center justify-center gap-2 rounded-full border border-landing-border-strong px-7 py-4 font-sans text-sm font-medium text-landing-text transition-colors duration-300 hover:border-landing-text hover:bg-landing-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-surface"
              >
                Talk to our team
              </a>
            </div>

            <div
              data-reveal
              data-reveal-delay="0.2"
              className="mt-12 flex flex-col gap-4 border-t border-landing-border pt-8 sm:flex-row sm:gap-10"
            >
              <a
                href={`mailto:${COMPANY.email}`}
                className="flex items-center gap-2.5 font-mono text-[12px] text-landing-text-secondary transition-colors hover:text-landing-accent"
              >
                <Mail size={15} className="text-landing-accent" />
                {COMPANY.email}
              </a>
              <span className="flex items-center gap-2.5 font-mono text-[12px] text-landing-text-secondary">
                <Phone size={15} className="text-landing-accent" />
                {COMPANY.phone}
              </span>
              <span className="font-mono text-[12px] text-landing-subtle-text">
                {COMPANY.locationLine}
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
