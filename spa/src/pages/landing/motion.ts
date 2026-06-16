/**
 * Landing motion layer — GSAP + Lenis.
 *
 * One place owns the page-level motion lifecycle:
 *   • Lenis smooth scrolling, driven by the GSAP ticker and synced to
 *     ScrollTrigger so pinned sections and reveals stay frame-accurate.
 *   • A declarative reveal system: any element marked `data-reveal` fades/rises
 *     in once as it enters the viewport. `data-reveal-delay="0.1"` staggers it.
 *
 * Accessibility contract: when `prefers-reduced-motion: reduce` is set we wire
 * up nothing — content is visible by default in the markup, smooth scroll is
 * left native, and ScrollTrigger never hides anything.
 */

import { useLayoutEffect, type RefObject } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';

let registered = false;
export function registerScrollTrigger() {
  if (!registered) {
    gsap.registerPlugin(ScrollTrigger);
    registered = true;
  }
}

export function reduceMotion(): boolean {
  return (
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
  );
}

/**
 * Page-level smooth scroll + scroll reveals, scoped to `rootRef`.
 * Call once from the landing page root.
 */
export function useLandingMotion(rootRef: RefObject<HTMLElement>) {
  useLayoutEffect(() => {
    const root = rootRef.current;
    if (!root || reduceMotion()) return;

    registerScrollTrigger();

    // ── Smooth scroll ────────────────────────────────────────────────
    const lenis = new Lenis({
      duration: 1.05,
      easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
      smoothWheel: true,
      wheelMultiplier: 1,
      touchMultiplier: 1.4,
    });
    lenis.on('scroll', ScrollTrigger.update);
    const ticker = (time: number) => lenis.raf(time * 1000);
    gsap.ticker.add(ticker);
    gsap.ticker.lagSmoothing(0);

    // Expose Lenis on window so floating UI (back-to-top, quote button) can
    // reuse the same smooth-scroll instance.
    (window as unknown as { lenis?: Lenis }).lenis = lenis;

    // Make in-page anchor links use Lenis for a smooth glide.
    function onAnchorClick(e: MouseEvent) {
      const link = (e.target as HTMLElement)?.closest('a[href^="#"]');
      if (!link) return;
      const id = link.getAttribute('href');
      if (!id || id === '#') return;
      const target = document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      lenis.scrollTo(target as HTMLElement, { offset: -72 });
    }
    root.addEventListener('click', onAnchorClick);

    // ── Scroll reveals ───────────────────────────────────────────────
    const ctx = gsap.context(() => {
      const els = gsap.utils.toArray<HTMLElement>('[data-reveal]');
      els.forEach((el) => {
        const delay = parseFloat(el.dataset.revealDelay ?? '0') || 0;
        gsap.fromTo(
          el,
          { autoAlpha: 0, y: 30 },
          {
            autoAlpha: 1,
            y: 0,
            duration: 0.95,
            ease: 'power3.out',
            delay,
            scrollTrigger: { trigger: el, start: 'top 86%', once: true },
          },
        );
      });
    }, root);

    // Recalculate once fonts/images settle.
    const refresh = () => ScrollTrigger.refresh();
    const refreshTimer = window.setTimeout(refresh, 350);
    if (document.fonts?.ready) document.fonts.ready.then(refresh).catch(() => {});

    return () => {
      window.clearTimeout(refreshTimer);
      root.removeEventListener('click', onAnchorClick);
      gsap.ticker.remove(ticker);
      lenis.destroy();
      delete (window as unknown as { lenis?: Lenis }).lenis;
      ctx.revert();
    };
  }, [rootRef]);
}
