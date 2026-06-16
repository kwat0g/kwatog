/**
 * MarqueeSection — quiet, high-trust band of the automakers Ogami supplies.
 *
 * Plain typographic wordmarks (nominative use, no logo artwork), scrolling in a
 * seamless loop. Pauses on hover and for reduced-motion users.
 */

import { OEM_PARTNERS } from '../data';

export function MarqueeSection() {
  // Two copies back-to-back so the -50% translate loops seamlessly.
  const row = [...OEM_PARTNERS, ...OEM_PARTNERS];

  return (
    <section
      aria-label="Automakers we supply"
      className="relative border-y border-landing-border bg-landing-canvas py-10"
    >
      <p
        data-reveal
        className="mb-7 text-center font-mono text-[11px] uppercase tracking-[0.28em] text-landing-subtle-text"
      >
        Trusted by the world&apos;s leading automakers
      </p>

      <div
        className="group relative flex overflow-hidden [--edge:6%] sm:[--edge:12%] [mask-image:linear-gradient(90deg,transparent,#000_var(--edge),#000_calc(100%-var(--edge)),transparent)] [-webkit-mask-image:linear-gradient(90deg,transparent,#000_var(--edge),#000_calc(100%-var(--edge)),transparent)]"
      >
        <ul className="flex shrink-0 items-center gap-16 pr-16 motion-safe:animate-marquee motion-safe:group-hover:[animation-play-state:paused] sm:gap-24 sm:pr-24">
          {row.map((name, i) => (
            <li
              key={`${name}-${i}`}
              aria-hidden={i >= OEM_PARTNERS.length}
              className="select-none font-display text-3xl font-semibold tracking-tight text-landing-muted transition-colors duration-300 hover:text-landing-text sm:text-4xl"
            >
              {name}
            </li>
          ))}
        </ul>
      </div>

      <p
        data-reveal
        className="mt-8 px-5 text-center font-mono text-[11px] uppercase tracking-[0.18em] text-landing-subtle-text"
      >
        5 global OEMs
        <span className="mx-2.5 text-landing-accent/50">·</span>
        200+ engineers
        <span className="mx-2.5 text-landing-accent/50">·</span>
        IATF 16949
        <span className="mx-2.5 text-landing-accent/50">·</span>
        ≤10 PPM target
      </p>
    </section>
  );
}
