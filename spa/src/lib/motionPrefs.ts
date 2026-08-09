/**
 * Reduced-motion preference — the one dependency-free motion helper.
 *
 * Lives here rather than in `pages/landing/motion.ts` because non-landing
 * surfaces (AuthLayout, login) only ever needed this three-line check, yet
 * importing it from the landing module pulled GSAP + ScrollTrigger + Lenis
 * into their chunk. `AuthLayout` is reachable statically from `App.tsx`, so
 * that dragged ~49 KB gzipped of motion library into the entry bundle for
 * every user on every route.
 *
 * Keep this file free of imports. `pages/landing/motion.ts` re-exports it so
 * landing-internal callers can keep importing from `../motion`.
 */
export function reduceMotion(): boolean {
  return (
    typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  );
}
