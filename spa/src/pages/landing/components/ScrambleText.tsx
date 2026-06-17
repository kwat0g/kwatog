/**
 * ScrambleText — a digital-readout "decode" of a label.
 *
 * The text resolves from a flicker of glyphs into the final string, like a metrology
 * gauge settling on a value or a CMM channel coming online. Used on mono eyebrows so
 * each section announces itself with a precise, instrument-like tick.
 *
 * Modes:
 *   • `trigger="mount"` — decode once on mount (default).
 *   • `trigger="view"`  — decode the first time it scrolls into view (ScrollTrigger).
 *   • `trigger="hover"` — re-decode on pointer enter; idle shows the final text.
 *
 * Accessibility / contract:
 *   • The real text is always present in the DOM (visually-hidden) for screen
 *     readers; a separate `aria-hidden` overlay carries the animated glyphs.
 *   • Under `prefers-reduced-motion` the final text shows immediately, no animation.
 *   • The interval is cleared on settle and on unmount; ScrollTrigger is killed.
 */

import { useEffect, useRef } from 'react';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { registerScrollTrigger, reduceMotion } from '../motion';

const GLYPHS = '0123456789±·ØXYZ#=/-—';

interface ScrambleTextProps {
  text: string;
  className?: string;
  /** ms per scramble frame. */
  speed?: number;
  /** total decode duration target, ms. */
  duration?: number;
  trigger?: 'mount' | 'view' | 'hover';
}

export function ScrambleText({
  text,
  className,
  speed = 36,
  duration = 620,
  trigger = 'mount',
}: ScrambleTextProps) {
  const hostRef = useRef<HTMLSpanElement>(null);
  const overlayRef = useRef<HTMLSpanElement>(null);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    const overlay = overlayRef.current;
    if (!overlay) return;

    function run() {
      if (!overlay) return;
      if (intervalRef.current) clearInterval(intervalRef.current);
      const total = Math.max(1, Math.round(duration / speed));
      let frame = 0;
      intervalRef.current = setInterval(() => {
        frame += 1;
        const settled = Math.floor((frame / total) * text.length);
        let out = '';
        for (let i = 0; i < text.length; i += 1) {
          const ch = text[i];
          if (ch === ' ') out += ' ';
          else if (i < settled) out += ch;
          else out += GLYPHS[Math.floor(Math.random() * GLYPHS.length)];
        }
        overlay.textContent = out;
        if (frame >= total) {
          overlay.textContent = text;
          if (intervalRef.current) clearInterval(intervalRef.current);
          intervalRef.current = null;
        }
      }, speed);
    }

    // Always seed the final text so there is never an empty/placeholder flash and
    // reduced-motion users simply keep it.
    overlay.textContent = text;
    if (reduceMotion()) return;

    const host = hostRef.current;
    let st: ScrollTrigger | null = null;
    let onEnter: (() => void) | null = null;

    if (trigger === 'mount') {
      run();
    } else if (trigger === 'view') {
      registerScrollTrigger();
      st = ScrollTrigger.create({
        trigger: host,
        start: 'top 88%',
        once: true,
        onEnter: run,
      });
    } else if (window.matchMedia('(pointer: fine)').matches) {
      // Hover re-decode is a fine-pointer affordance; on touch the label just
      // stays resolved (a tap should never trigger a scramble flicker).
      onEnter = run;
      host?.addEventListener('pointerenter', onEnter);
    }

    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
      intervalRef.current = null;
      st?.kill();
      if (onEnter) host?.removeEventListener('pointerenter', onEnter);
    };
  }, [trigger, text, speed, duration]);

  return (
    <span ref={hostRef} className={className}>
      <span className="sr-only">{text}</span>
      <span ref={overlayRef} aria-hidden="true">
        {text}
      </span>
    </span>
  );
}
