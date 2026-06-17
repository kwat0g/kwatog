/**
 * useTilt — a card that tips toward the pointer like a part held up to the light.
 *
 * On hover the element rotates a few degrees in 3D toward the cursor (GSAP writes
 * the transform, so the motion is smooth and interruptible), and an optional child
 * marked `[data-tilt-glow]` receives `--mx`/`--my` so a soft sheen can track the
 * pointer across the surface. A `[data-tilt-lift]` child can float forward in Z for
 * a parallax "raised feature" feel.
 *
 * Contract:
 *   • Inert under `prefers-reduced-motion: reduce` and on coarse/touch pointers.
 *   • The hook owns the element transform — do not also apply Tailwind `translate`/
 *     `rotate`/`scale` on the same node (use CSS for the glow/lift children instead).
 *   • Tweens killed and transform cleared on unmount.
 */

import { useLayoutEffect, useRef, type RefObject } from 'react';
import gsap from 'gsap';
import { reduceMotion } from '../motion';

interface TiltOptions {
  /** Max rotation in degrees at the corners. */
  max?: number;
  /** Perspective distance in px (smaller = stronger 3D). */
  perspective?: number;
  /** Z translation in px applied to `[data-tilt-lift]` while hovered. */
  lift?: number;
}

export function useTilt<T extends HTMLElement>(
  options: TiltOptions = {},
): RefObject<T> {
  const ref = useRef<T>(null);
  const { max = 6, perspective = 900, lift = 26 } = options;

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (reduceMotion() || !window.matchMedia('(pointer: fine)').matches) return;

    const glow = el.querySelector<HTMLElement>('[data-tilt-glow]');
    const liftEl = el.querySelector<HTMLElement>('[data-tilt-lift]');

    gsap.set(el, { transformPerspective: perspective, transformStyle: 'preserve-3d' });
    const ease = 'power2.out';
    const rx = gsap.quickTo(el, 'rotationX', { duration: 0.45, ease });
    const ry = gsap.quickTo(el, 'rotationY', { duration: 0.45, ease });
    const lz = liftEl ? gsap.quickTo(liftEl, 'z', { duration: 0.45, ease }) : null;

    function onMove(e: PointerEvent) {
      const r = el!.getBoundingClientRect();
      const px = (e.clientX - r.left) / r.width; // 0..1
      const py = (e.clientY - r.top) / r.height; // 0..1
      rx((0.5 - py) * max * 2);
      ry((px - 0.5) * max * 2);
      if (glow) {
        glow.style.setProperty('--mx', `${px * 100}%`);
        glow.style.setProperty('--my', `${py * 100}%`);
        glow.style.opacity = '1';
      }
      lz?.(lift);
    }
    function onLeave() {
      rx(0);
      ry(0);
      lz?.(0);
      if (glow) glow.style.opacity = '0';
    }

    el.addEventListener('pointermove', onMove, { passive: true });
    el.addEventListener('pointerleave', onLeave, { passive: true });
    return () => {
      el.removeEventListener('pointermove', onMove);
      el.removeEventListener('pointerleave', onLeave);
      gsap.killTweensOf(el);
      if (liftEl) {
        gsap.killTweensOf(liftEl);
        gsap.set(liftEl, { clearProps: 'z' });
      }
      gsap.set(el, { clearProps: 'transform,transformPerspective,transformStyle' });
    };
  }, [max, perspective, lift]);

  return ref;
}
