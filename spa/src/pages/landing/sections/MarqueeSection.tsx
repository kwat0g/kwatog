/**
 * MarqueeSection — quiet, high-trust band of the automakers Ogami supplies.
 *
 * Plain typographic wordmarks (nominative use, no logo artwork), scrolling in a
 * seamless loop. Pauses on hover and for reduced-motion users.
 *
 * Motion path: when motion is allowed, CSS animation is replaced by a GSAP
 * x-tween (xPercent -50 over 36 s, repeat -1) so scroll velocity from Lenis
 * can nudge timeScale each frame for a tactile elastic feel. That per-frame
 * loop is gated on visibility the same way the WebGL canvases are — off screen
 * or on a hidden tab, both the rAF and the tween stop.
 */

import { useLayoutEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import gsap from 'gsap';
import { landingApi } from '@/api/landing';
import { reduceMotion } from '../motion';

const EMPTY_PARTNERS: string[] = [];

export function MarqueeSection() {
  const { data: content } = useQuery({
    queryKey: ['landing', 'content'],
    queryFn: landingApi.content,
    staleTime: 300_000,
  });
  const partners = content?.oem_partners ?? EMPTY_PARTNERS;
  const trustPoints = content?.trust_points ?? [];
  const trustHeading = content?.section_copy?.trust_heading ?? '—';
  const stats = content?.stats ?? [];
  const statValue = (id: string) => {
    const stat = stats.find((item) => item.id === id);
    if (!stat) return '';
    return `${'prefix' in stat && stat.prefix ? stat.prefix : ''}${stat.value.toLocaleString()}${'suffix' in stat && stat.suffix ? stat.suffix : ''}`;
  };
  // Two copies back-to-back so the -50% translate loops seamlessly.
  const row = [...partners, ...partners];
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
    //
    // Gated exactly like the two WebGL canvases (HeroCanvas / PartShowcase3D):
    // the loop only runs while the band is on screen AND the tab is visible.
    // Ungated it ticked forever once scrolled past — a permanent wake-up on the
    // phones this page is actually read on. The tween is paused alongside it so
    // the row does not silently travel while nobody can see it.
    let rafId = 0;
    let hovered = false;
    let inView = false;

    function tick() {
      if (document.hidden || !inView) {
        rafId = 0;
        return;
      }
      const velocity =
        (window as unknown as { lenis?: { velocity?: number } }).lenis?.velocity ?? 0;
      const speed = Math.min(Math.abs(velocity) * 0.04, 1.6);
      const target = hovered ? 0 : 1 + speed;
      const current = tween.timeScale();
      tween.timeScale(current + (target - current) * 0.08);
      rafId = requestAnimationFrame(tick);
    }

    function start() {
      if (rafId || document.hidden || !inView) return;
      tween.play();
      rafId = requestAnimationFrame(tick);
    }
    function stop() {
      if (rafId) cancelAnimationFrame(rafId);
      rafId = 0;
      tween.pause();
    }

    // Start paused — the observer starts the loop the moment the band enters
    // view, so a below-the-fold mount costs nothing.
    tween.pause();

    const io = new IntersectionObserver(
      ([entry]) => {
        inView = entry.isIntersecting;
        if (inView) start();
        else stop();
      },
      { threshold: 0 },
    );
    io.observe(ul);

    function onVisibility() {
      if (document.hidden) stop();
      else start();
    }
    document.addEventListener('visibilitychange', onVisibility);

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
      stop();
      io.disconnect();
      document.removeEventListener('visibilitychange', onVisibility);
      container?.removeEventListener('pointerenter', onEnter);
      container?.removeEventListener('pointerleave', onLeave);
      tween.kill();
      gsap.set(ul, { clearProps: 'x,xPercent' });
    };
  }, [partners]);

  return (
    <section
      aria-label="Automakers we supply"
      className="relative border-y border-default bg-canvas py-10"
    >
      <p
        data-reveal
        className="mb-7 px-5 text-center font-mono text-[11px] uppercase tracking-[0.28em] text-text-subtle sm:px-5"
      >
        {trustHeading}
      </p>

      <div className="group relative flex overflow-hidden [--edge:6%] sm:[--edge:12%] [mask-image:linear-gradient(90deg,transparent,#000_var(--edge),#000_calc(100%-var(--edge)),transparent)] [-webkit-mask-image:linear-gradient(90deg,transparent,#000_var(--edge),#000_calc(100%-var(--edge)),transparent)]">
        <ul
          ref={ulRef}
          className="flex shrink-0 items-center gap-16 pr-16 will-change-transform sm:gap-24 sm:pr-24"
        >
          {row.map((name, i) => (
            <li
              key={`${name}-${i}`}
              aria-hidden={i >= partners.length}
              className="select-none font-display text-4xl font-semibold tracking-[-0.02em] text-muted transition-all duration-300 hover:text-primary hover:scale-105 sm:text-5xl"
            >
              {name}
            </li>
          ))}
        </ul>
      </div>

      <p
        data-reveal
        className="mt-8 px-5 text-center font-mono text-[11px] uppercase tracking-[0.18em] text-text-subtle sm:px-5"
      >
        {statValue('employees') || '—'} active employees
        <span className="mx-2.5 text-accent/50">·</span>
        {statValue('customers') || '—'} active customers
        <span className="mx-2.5 text-accent/50">·</span>
        {statValue('products') || '—'} active products
        {trustPoints.length > 0 && (
          <>
            <span className="mx-2.5 text-accent/50">·</span>
            {trustPoints[0]}
          </>
        )}
      </p>
    </section>
  );
}
