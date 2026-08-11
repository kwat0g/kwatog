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
 * Accessibility contract: reveals animate `opacity`/`transform` ONLY — never
 * `visibility`. GSAP's `autoAlpha` shorthand writes `visibility: hidden` as soon
 * as opacity reaches 0, which pulls the node out of the accessibility tree
 * entirely: `textContent` still holds the words but `innerText` is empty and
 * axe cannot compute an accessible name, so every heading below the fold was
 * invisible to screen readers until a sighted user scrolled it into view.
 * Opacity alone animates identically and keeps the text nameable the whole time.
 *
 * When `prefers-reduced-motion: reduce` is set we wire up nothing — content is
 * visible by default in the markup, smooth scroll is left native, and
 * ScrollTrigger never hides anything. The preference is watched live, so
 * enabling it mid-session tears the motion layer down and restores every
 * element rather than leaving reveals stuck part-way.
 */

import { useEffect, useLayoutEffect, useState, type RefObject } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import { reduceMotion } from '@/lib/motionPrefs';

// Re-exported for landing-internal callers (`import { reduceMotion } from
// '../motion'`). The implementation lives in `@/lib/motionPrefs` — a file with
// no imports — so surfaces outside the landing page can read the preference
// without pulling GSAP/Lenis into their chunk. Import it from there, not here.
export { reduceMotion };

let registered = false;
export function registerScrollTrigger() {
  if (!registered) {
    gsap.registerPlugin(ScrollTrigger);
    registered = true;
  }
}

/**
 * Live `prefers-reduced-motion`, as React state.
 *
 * The plain {@link reduceMotion} read happens once at mount; a user who turns
 * the OS setting on mid-visit would keep a running motion layer. Returning it
 * as state lets the caller's effect re-run and tear down.
 */
function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(reduceMotion);

  useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    const sync = () => setReduced(query.matches);
    sync();
    query.addEventListener('change', sync);
    return () => query.removeEventListener('change', sync);
  }, []);

  return reduced;
}

/**
 * Page-level smooth scroll + scroll reveals, scoped to `rootRef`.
 * Call once from the landing page root.
 */
export function useLandingMotion(rootRef: RefObject<HTMLElement>) {
  const reduced = useReducedMotion();

  useLayoutEffect(() => {
    const root = rootRef.current;
    if (!root || reduced) return;

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
    //
    // `opacity`, never `autoAlpha` — see the accessibility contract at the top
    // of this file. An element awaiting its reveal is transparent but still
    // laid out, focusable, and nameable by assistive tech.
    const revealFrom: Record<string, gsap.TweenVars> = {
      '': { opacity: 0, y: 18 },
      up: { opacity: 0, y: 18 },
      left: { opacity: 0, x: -28 },
      right: { opacity: 0, x: 28 },
      scale: { opacity: 0, scale: 0.94, y: 14 },
      clip: { opacity: 0, y: 14, clipPath: 'inset(0 0 100% 0)' },
    };

    let revealEls: HTMLElement[] = [];

    const ctx = gsap.context(() => {
      const els = gsap.utils.toArray<HTMLElement>('[data-reveal]');
      revealEls = els;
      els.forEach((el) => {
        const variant = el.dataset.reveal || '';
        const from = revealFrom[variant] ?? revealFrom[''];
        // Clamp the stagger: a deep grid card (i*0.08) could otherwise wait
        // ~0.5s on top of the tween, reading as "late to appear" on fast scroll.
        const delay = Math.min(parseFloat(el.dataset.revealDelay ?? '0') || 0, 0.24);
        gsap.fromTo(el, from, {
          opacity: 1,
          x: 0,
          y: 0,
          scale: 1,
          clipPath: variant === 'clip' ? 'inset(0 0 0% 0)' : undefined,
          duration: 0.5,
          ease: 'power2.out',
          delay,
          // Fire earlier and let it catch up if scrolled past mid-tween.
          scrollTrigger: { trigger: el, start: 'top 92%', once: true, fastScrollEnd: true },
        });
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

    // Printing does not scroll, so anything still awaiting its reveal would
    // come off the printer blank. Resolve every pending element up front.
    function revealAll() {
      if (revealEls.length === 0) return;
      gsap.set(revealEls, { opacity: 1, x: 0, y: 0, scale: 1, clipPath: 'none' });
    }
    window.addEventListener('beforeprint', revealAll);

    // Recalculate once fonts/images settle.
    const refresh = () => ScrollTrigger.refresh();
    const refreshTimer = window.setTimeout(refresh, 350);
    if (document.fonts?.ready) document.fonts.ready.then(refresh).catch(() => {});

    return () => {
      window.clearTimeout(refreshTimer);
      window.removeEventListener('beforeprint', revealAll);
      root.removeEventListener('click', onAnchorClick);
      gsap.ticker.remove(ticker);
      lenis.destroy();
      delete (window as unknown as { lenis?: Lenis }).lenis;
      // Restores the inline styles GSAP wrote, so a mid-session switch to
      // reduced motion leaves every element at its natural, visible state.
      ctx.revert();
    };
  }, [rootRef, reduced]);
}
