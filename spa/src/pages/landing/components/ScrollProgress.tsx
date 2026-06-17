/**
 * ScrollProgress — a measurement-tape read of page progress.
 *
 * A hairline rule pinned to the very top of the viewport whose fill tracks how far
 * the document has been scrolled, with faint tick marks every 10% like an engineer's
 * scale and a live percentage readout. Driven by Lenis when present (so it shares the
 * page's smooth-scroll clock) and by a passive scroll listener otherwise.
 *
 * Contract: under `prefers-reduced-motion` it still reports position (a progress
 * indicator is information, not decoration) but updates without easing.
 */

import { useEffect, useRef } from 'react';

type LenisLike = {
  on: (event: 'scroll', cb: (e: { progress?: number }) => void) => void;
  off?: (event: 'scroll', cb: (e: { progress?: number }) => void) => void;
};

export function ScrollProgress() {
  const fillRef = useRef<HTMLDivElement>(null);
  const readoutRef = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    const fill = fillRef.current;
    if (!fill) return;

    let raf = 0;
    function paint(progress: number) {
      const p = Math.min(1, Math.max(0, progress));
      fill!.style.transform = `scaleX(${p})`;
      if (readoutRef.current) {
        readoutRef.current.textContent = `${Math.round(p * 100)}%`;
      }
    }

    function computeNative(): number {
      const doc = document.documentElement;
      const max = doc.scrollHeight - doc.clientHeight;
      return max > 0 ? doc.scrollTop / max : 0;
    }

    function onScrollNative() {
      if (raf) return;
      raf = requestAnimationFrame(() => {
        raf = 0;
        paint(computeNative());
      });
    }

    // Prefer the shared Lenis instance the landing motion layer publishes.
    const lenis = (window as unknown as { lenis?: LenisLike }).lenis;
    const onLenis = (e: { progress?: number }) =>
      paint(typeof e.progress === 'number' ? e.progress : computeNative());

    if (lenis) {
      lenis.on('scroll', onLenis);
    } else {
      window.addEventListener('scroll', onScrollNative, { passive: true });
    }
    paint(computeNative());

    return () => {
      if (raf) cancelAnimationFrame(raf);
      if (lenis?.off) lenis.off('scroll', onLenis);
      else window.removeEventListener('scroll', onScrollNative);
    };
  }, []);

  return (
    <div
      aria-hidden="true"
      className="pointer-events-none fixed inset-x-0 top-0 z-[55] h-px"
    >
      {/* tape ticks every 10% */}
      <div
        className="absolute inset-0 opacity-50"
        style={{
          backgroundImage:
            'repeating-linear-gradient(90deg, var(--landing-border-strong) 0 1px, transparent 1px 10%)',
        }}
      />
      <div
        ref={fillRef}
        className="absolute inset-y-0 left-0 w-full origin-left bg-landing-accent"
        style={{ transform: 'scaleX(0)' }}
      />
      <span
        ref={readoutRef}
        className="absolute right-2 top-1.5 font-mono text-[9px] tabular-nums tracking-[0.1em] text-landing-subtle-text"
      >
        0%
      </span>
    </div>
  );
}
