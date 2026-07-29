/**
 * The keyboard focus ring, in one place. Every interactive element that is not a
 * `Button`/`Input`/`Select` (clickable table rows, menu items, disclosure
 * triangles, dropdown triggers) pulls its ring from here so a keyboard user
 * sees the same indicator everywhere.
 *
 * Use `focusRing` for controls with space around them, and `focusRingInset` for
 * anything flush against its container — a menu item, a full-width list row, a
 * cell affordance — where an offset ring would be clipped by the parent's
 * `overflow-hidden`.
 */
export const focusRing =
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1';

export const focusRingInset =
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset';

/**
 * The landing/marketing pages run on their own light palette, so their ring is
 * the landing accent offset against the landing canvas.
 */
export const focusRingLanding =
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas';

