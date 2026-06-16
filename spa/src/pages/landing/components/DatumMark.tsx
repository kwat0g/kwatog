/**
 * DatumMark — a metrology datum / center-target, the kind printed on an
 * engineering drawing to mark a reference point. It replaces the earlier sun
 * motif with something genuinely connected to precision manufacturing: this is
 * the origin every measured dimension is taken from.
 *
 * A concentric ring with a crosshair whose lines overshoot the circle (true
 * drafting center-mark convention). Inherits `currentColor`, so it tints to ink
 * or accent wherever placed. Used as the brand glyph and as a quiet motif.
 */

interface DatumMarkProps {
  size?: number;
  className?: string;
  strokeWidth?: number;
  /** Fill the inner dot (brand glyph) vs leave hollow (decorative). */
  solidCore?: boolean;
  title?: string;
}

export function DatumMark({
  size = 24,
  className,
  strokeWidth = 1.4,
  solidCore = true,
  title,
}: DatumMarkProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      className={className}
      role={title ? 'img' : 'presentation'}
      aria-hidden={title ? undefined : true}
      aria-label={title}
    >
      {title ? <title>{title}</title> : null}

      {/* Outer reference ring */}
      <circle cx="12" cy="12" r="8.5" opacity="0.55" />
      {/* Inner ring */}
      <circle cx="12" cy="12" r="4.4" />

      {/* Crosshair — overshoots the outer ring (center-mark convention) */}
      <line x1="12" y1="1.5" x2="12" y2="7.6" />
      <line x1="12" y1="16.4" x2="12" y2="22.5" />
      <line x1="1.5" y1="12" x2="7.6" y2="12" />
      <line x1="16.4" y1="12" x2="22.5" y2="12" />

      {/* Origin */}
      {solidCore ? (
        <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
      ) : (
        <circle cx="12" cy="12" r="1.1" fill="currentColor" stroke="none" />
      )}
    </svg>
  );
}
