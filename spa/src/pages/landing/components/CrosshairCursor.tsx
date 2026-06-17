/**
 * CrosshairCursor — a coordinate-measuring-machine (CMM) probe for the cursor.
 *
 * Within its scope element the native cursor is hidden and replaced by a precision
 * reticle: a fast inner dot, a softly-trailing ring, and a live X/Y readout in
 * millimetre-style units taken from the scope's centre datum. Over any interactive
 * target the ring locks into registration brackets — the probe "touching a feature".
 *
 * Pure enhancement, progressively applied:
 *   • Renders nothing (native cursor untouched) under `prefers-reduced-motion`, on
 *     coarse/touch pointers, or where hover is unavailable.
 *   • `cursor: none` is only written after the reticle is live, so a failure can
 *     never strand the user without a cursor.
 *   • Listeners are passive/window-scoped and fully torn down on unmount.
 */

import { useLayoutEffect, useRef, type RefObject } from 'react';
import gsap from 'gsap';
import { reduceMotion } from '../motion';

interface CrosshairCursorProps {
  /** Element whose surface adopts the probe (native cursor hidden within it). */
  scopeRef: RefObject<HTMLElement>;
}

const INTERACTIVE =
  'a,button,input,textarea,select,label,[role="button"],[data-cursor-lock]';

export function CrosshairCursor({ scopeRef }: CrosshairCursorProps) {
  const rootRef = useRef<HTMLDivElement>(null);
  const dotRef = useRef<HTMLDivElement>(null);
  const ringRef = useRef<HTMLDivElement>(null);
  const bracketsRef = useRef<HTMLDivElement>(null);
  const xRef = useRef<HTMLSpanElement>(null);
  const yRef = useRef<HTMLSpanElement>(null);

  useLayoutEffect(() => {
    const scope = scopeRef.current;
    const root = rootRef.current;
    const dot = dotRef.current;
    const ring = ringRef.current;
    const brackets = bracketsRef.current;
    if (!scope || !root || !dot || !ring || !brackets) return;
    if (
      reduceMotion() ||
      !window.matchMedia('(pointer: fine)').matches ||
      !window.matchMedia('(hover: hover)').matches ||
      // The reticle is `display:none` below lg — never hide the native cursor
      // on smaller viewports or the user would be left with no pointer at all.
      !window.matchMedia('(min-width: 1024px)').matches
    ) {
      return;
    }

    scope.style.cursor = 'none';

    const dotX = gsap.quickTo(dot, 'x', { duration: 0.08, ease: 'power2.out' });
    const dotY = gsap.quickTo(dot, 'y', { duration: 0.08, ease: 'power2.out' });
    const ringX = gsap.quickTo(ring, 'x', { duration: 0.34, ease: 'power3.out' });
    const ringY = gsap.quickTo(ring, 'y', { duration: 0.34, ease: 'power3.out' });

    let inScope = false;
    let locked = false;
    let pressed = false;
    let rafQueued = false;
    let readoutRaf = 0;
    let lastX = 0;
    let lastY = 0;

    function applyRing() {
      const scale = (locked ? 1.5 : 1) * (pressed ? 0.85 : 1);
      gsap.to(ring, {
        scale,
        borderRadius: locked ? 6 : 999,
        borderColor: locked
          ? 'var(--landing-accent)'
          : 'color-mix(in srgb, var(--landing-accent) 55%, transparent)',
        duration: 0.3,
        ease: 'power3.out',
      });
      gsap.to(brackets, { opacity: locked ? 1 : 0, duration: 0.2, ease: 'power2.out' });
    }

    function paintReadout() {
      rafQueued = false;
      const r = scope!.getBoundingClientRect();
      const mmX = ((lastX - (r.left + r.width / 2)) / r.width) * 200;
      const mmY = ((r.top + r.height / 2 - lastY) / r.height) * 200;
      if (xRef.current) {
        xRef.current.textContent = `${mmX >= 0 ? '+' : '−'}${Math.abs(mmX).toFixed(1)}`;
      }
      if (yRef.current) {
        yRef.current.textContent = `${mmY >= 0 ? '+' : '−'}${Math.abs(mmY).toFixed(1)}`;
      }
    }

    function onMove(e: PointerEvent) {
      lastX = e.clientX;
      lastY = e.clientY;
      dotX(e.clientX);
      dotY(e.clientY);
      ringX(e.clientX);
      ringY(e.clientY);
      if (!rafQueued) {
        rafQueued = true;
        readoutRaf = requestAnimationFrame(paintReadout);
      }

      const target = e.target as HTMLElement | null;
      const nowInScope = !!target?.closest?.('[data-crosshair-scope]');
      if (nowInScope !== inScope) {
        inScope = nowInScope;
        gsap.to(root, { autoAlpha: nowInScope ? 1 : 0, duration: 0.25, ease: 'power2.out' });
      }

      const nowLocked = !!target?.closest?.(INTERACTIVE);
      if (nowLocked !== locked) {
        locked = nowLocked;
        applyRing();
      }
    }

    function onDown() {
      pressed = true;
      applyRing();
    }
    function onUp() {
      pressed = false;
      applyRing();
    }

    gsap.set(root, { autoAlpha: 0 });
    window.addEventListener('pointermove', onMove, { passive: true });
    window.addEventListener('pointerdown', onDown, { passive: true });
    window.addEventListener('pointerup', onUp, { passive: true });

    return () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerdown', onDown);
      window.removeEventListener('pointerup', onUp);
      if (readoutRaf) cancelAnimationFrame(readoutRaf);
      gsap.killTweensOf([dot, ring, root, brackets]);
      scope.style.cursor = '';
    };
  }, [scopeRef]);

  return (
    <div
      ref={rootRef}
      aria-hidden="true"
      className="pointer-events-none fixed left-0 top-0 z-[100] hidden lg:block"
      style={{ opacity: 0, visibility: 'hidden' }}
    >
      {/* Trailing ring — locks into registration brackets over interactive targets. */}
      <div ref={ringRef} className="absolute -ml-5 -mt-5 h-10 w-10">
        <div className="absolute inset-0 rounded-full border border-landing-accent/60" />
        <div ref={bracketsRef} className="absolute inset-0" style={{ opacity: 0 }}>
          {[
            'left-0 top-0 border-l border-t',
            'right-0 top-0 border-r border-t',
            'left-0 bottom-0 border-b border-l',
            'right-0 bottom-0 border-b border-r',
          ].map((pos) => (
            <span key={pos} className={`absolute h-2 w-2 border-landing-accent ${pos}`} />
          ))}
        </div>
      </div>

      {/* Precise inner dot + crosshair ticks + readout. */}
      <div ref={dotRef} className="absolute left-0 top-0">
        <span className="absolute left-1/2 top-1/2 h-[3px] w-[3px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-landing-accent" />
        <span className="absolute left-1/2 top-1/2 h-px w-2 -translate-x-1/2 -translate-y-1/2 bg-landing-accent/50" />
        <span className="absolute left-1/2 top-1/2 h-2 w-px -translate-x-1/2 -translate-y-1/2 bg-landing-accent/50" />
        <span className="absolute left-4 top-3 whitespace-nowrap font-mono text-[9px] uppercase tracking-[0.12em] text-landing-muted">
          X<span ref={xRef} className="ml-1 tabular-nums text-landing-text">+0.0</span>
          <span className="mx-1.5 text-landing-subtle-text">·</span>
          Y<span ref={yRef} className="ml-1 tabular-nums text-landing-text">+0.0</span>
        </span>
      </div>
    </div>
  );
}
