/**
 * MarqueeSection — quiet, high-trust band of the automakers Ogami supplies.
 *
 * Plain typographic wordmarks (nominative use, no logo artwork), scrolling in a
 * seamless loop. Pauses on hover and for reduced-motion users.
 *
 * Motion path: when motion is allowed, CSS animation is replaced by a GSAP
 * x-tween (xPercent -50 over 36 s, repeat -1) so scroll velocity from Lenis
 * can nudge timeScale each frame for a tactile elastic feel.
 */

import { useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { OEM_PARTNERS } from '../data';
import { reduceMotion } from '../motion';

export function MarqueeSection() {
  // Two copies back-to-back so the -50% translate loops seamlessly.
  const row = [...OEM_PARTNERS, ...OEM_PARTNERS];
  const ulRef = useRef<HTMLUListElement>(null);

  useLayoutEffect(() => {
    if (reduceMotion()) return;

    const ul = ulRef.current;
    if (!ul) return;

    // Build the GSAP tween: seamless -50% loop (two copies = one full set).
    // (The CSS `animate-marquee` is intentionally absent from the markup so GSAP
    //  is the sole driver of the transform; reduced-motion leaves the row static.)
    const tween = gsap.to(ul, {
      xPercent: -50,
      duration: 36,
      ease: 'none',
      repeat: -1,
    });

    // Single rAF drives both hover-pause and scroll-velocity reactivity by
    // lerping the tween's timeScale toward one target each frame — so the two
    // never fight (a separate gsap.to on timeScale would be overwritten here).
    let rafId = 0;
    let hovered = false;

    function tick() {
      const velocity =
        (window as unknown as { lenis?: { velocity?: number } }).lenis?.velocity ?? 0;
      const speed = Math.min(Math.abs(velocity) * 0.04, 1.6);
      const target = hovered ? 0 : 1 + speed;
      const current = tween.timeScale();
      tween.timeScale(current + (target - current) * 0.08);
      rafId = requestAnimationFrame(tick);
    }
    rafId = requestAnimationFrame(tick);

    // Hover: ease to a stop (and resume) via the shared target above.
    const container = ul.parentElement;
    function onEnter() {
      hovered = true;
    }
    function onLeave() {
      hovered = false;
    }

    container?.addEventListener('pointerenter', onEnter, { passive: true });
    container?.addEventListener('pointerleave', onLeave, { passive: true });

    return () => {
      cancelAnimationFrame(rafId);
      container?.removeEventListener('pointerenter', onEnter);
      container?.removeEventListener('pointerleave', onLeave);
      tween.kill();
      gsap.set(ul, { clearProps: 'x,xPercent' });
    };
  }, []);

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
        <ul
          ref={ulRef}
          className="flex shrink-0 items-center gap-16 pr-16 will-change-transform sm:gap-24 sm:pr-24"
        >
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
