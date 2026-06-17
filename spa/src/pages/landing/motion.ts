/**
 * Landing motion layer — GSAP + Lenis.
 *
 * One place owns the page-level motion lifecycle:
 *   • Lenis smooth scrolling, driven by the GSAP ticker and synced to
 *     ScrollTrigger so pinned sections and reveals stay frame-accurate. The
 *     instance (with its live `.velocity`) is published on `window.lenis` for
 *     floating UI, the scroll-progress bar, and velocity-reactive components.
 *   • A declarative reveal system: any element marked `data-reveal` animates in
 *     once as it enters the viewport. The value picks the gesture
 *     (`""|up|left|right|scale|clip`) and `data-reveal-delay="0.1"` staggers it.
 *   • A declarative parallax system: `data-parallax="12"` drifts a decorative
 *     layer ±12% of its height across the scroll for quiet depth.
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
    // Each variant is the "from" state; the tween always resolves to the
    // composed/visible value. Keep them gentle — this is precision, not flair.
    const revealFrom: Record<string, gsap.TweenVars> = {
      '': { autoAlpha: 0, y: 18 },
      up: { autoAlpha: 0, y: 18 },
      left: { autoAlpha: 0, x: -28 },
      right: { autoAlpha: 0, x: 28 },
      scale: { autoAlpha: 0, scale: 0.94, y: 14 },
      clip: { autoAlpha: 0, y: 14, clipPath: 'inset(0 0 100% 0)' },
    };

    const ctx = gsap.context(() => {
      const els = gsap.utils.toArray<HTMLElement>('[data-reveal]');
      els.forEach((el) => {
        const variant = el.dataset.reveal || '';
        const from = revealFrom[variant] ?? revealFrom[''];
        // Clamp the stagger: a deep grid card (i*0.08) could otherwise wait
        // ~0.5s on top of the tween, reading as "late to appear" on fast scroll.
        const delay = Math.min(parseFloat(el.dataset.revealDelay ?? '0') || 0, 0.24);
        gsap.fromTo(
          el,
          from,
          {
            autoAlpha: 1,
            x: 0,
            y: 0,
            scale: 1,
            clipPath: variant === 'clip' ? 'inset(0 0 0% 0)' : undefined,
            duration: 0.5,
            ease: 'power2.out',
            delay,
            // Fire earlier and let it catch up if scrolled past mid-tween.
            scrollTrigger: { trigger: el, start: 'top 92%', once: true, fastScrollEnd: true },
          },
        );
      });

      // ── Parallax depth ─────────────────────────────────────────────
      // Decorative layers only (they own their transform); never combine with
      // data-reveal/tilt/magnetic on the same node.
      const parallax = gsap.utils.toArray<HTMLElement>('[data-parallax]');
      parallax.forEach((el) => {
        const amount = parseFloat(el.dataset.parallax ?? '10') || 10;
        gsap.fromTo(
          el,
          { yPercent: amount },
          {
            yPercent: -amount,
            ease: 'none',
            scrollTrigger: {
              trigger: el.parentElement ?? el,
              start: 'top bottom',
              end: 'bottom top',
              scrub: true,
              invalidateOnRefresh: true,
            },
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
