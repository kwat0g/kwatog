/**
 * useMagnetic — a precise magnetic pull toward the pointer.
 *
 * The element drifts toward the cursor while hovered and springs back to rest on
 * leave, like a probe attracted to a feature. GSAP `quickTo` keeps it buttery and
 * interruptible. An optional inner "label" element trails at a softer ratio so the
 * icon/text floats slightly behind the body — a subtle parallax that reads as depth.
 *
 * Accessibility / performance contract:
 *   • Inert under `prefers-reduced-motion: reduce`.
 *   • Inert on coarse/touch pointers — only fine pointers (mouse/trackpad) opt in.
 *   • Listeners are passive; tweens are killed on unmount.
 *
 * The element must establish its own transform context (the hook writes x/y), so
 * never combine it with a Tailwind `translate-*` hover on the same node.
 */

import { useLayoutEffect, useRef, type RefObject } from 'react';
import gsap from 'gsap';
import { reduceMotion } from '../motion';

interface MagneticOptions {
  /** Fraction of the cursor offset the body follows (0–1). */
  strength?: number;
  /** Fraction the inner `[data-magnetic-label]` follows; usually < strength. */
  labelStrength?: number;
  /** Spring duration back to rest, seconds. */
  duration?: number;
}

export function useMagnetic<T extends HTMLElement>(
  options: MagneticOptions = {},
): RefObject<T> {
  const ref = useRef<T>(null);
  const { strength = 0.32, labelStrength = 0.18, duration = 0.6 } = options;

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (reduceMotion() || !window.matchMedia('(pointer: fine)').matches) return;

    const label = el.querySelector<HTMLElement>('[data-magnetic-label]');
    const ease = 'power3.out';
    const bx = gsap.quickTo(el, 'x', { duration, ease });
    const by = gsap.quickTo(el, 'y', { duration, ease });
    const lx = label ? gsap.quickTo(label, 'x', { duration, ease }) : null;
    const ly = label ? gsap.quickTo(label, 'y', { duration, ease }) : null;

    function onMove(e: PointerEvent) {
      const r = el!.getBoundingClientRect();
      const dx = e.clientX - (r.left + r.width / 2);
      const dy = e.clientY - (r.top + r.height / 2);
      bx(dx * strength);
      by(dy * strength);
      lx?.(dx * labelStrength);
      ly?.(dy * labelStrength);
    }
    function onLeave() {
      bx(0);
      by(0);
      lx?.(0);
      ly?.(0);
    }

    el.addEventListener('pointermove', onMove, { passive: true });
    el.addEventListener('pointerleave', onLeave, { passive: true });
    return () => {
      el.removeEventListener('pointermove', onMove);
      el.removeEventListener('pointerleave', onLeave);
      gsap.killTweensOf(el);
      if (label) gsap.killTweensOf(label);
    };
  }, [strength, labelStrength, duration]);

  return ref;
}
