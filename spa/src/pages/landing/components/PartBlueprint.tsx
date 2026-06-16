/**
 * PartBlueprint — a static engineering drawing of a molded bushing/cap.
 *
 * Serves two roles: the always-present base layer inside the hero drawing
 * frame, and the full fallback when WebGL/motion is unavailable. Pure SVG — a
 * sectioned part profile with a centerline, hatching, and dimension witness
 * lines, drawn in ink with one espresso dimension to echo the page accent.
 */

import { cn } from '@/lib/cn';

interface PartBlueprintProps {
  className?: string;
}

export function PartBlueprint({ className }: PartBlueprintProps) {
  return (
    <svg
      viewBox="0 0 240 220"
      fill="none"
      className={cn('h-full w-full', className)}
      style={{ color: 'var(--landing-ink)' }}
      aria-hidden="true"
    >
      <defs>
        <pattern id="hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
          <line x1="0" y1="0" x2="0" y2="6" stroke="currentColor" strokeWidth="0.6" opacity="0.25" />
        </pattern>
      </defs>

      {/* Vertical centerline (axis of revolution) — long-dash / dot */}
      <line
        x1="120"
        y1="18"
        x2="120"
        y2="202"
        stroke="currentColor"
        strokeWidth="0.8"
        strokeDasharray="10 3 2 3"
        opacity="0.55"
      />

      {/* Part section — mirrored, sectioned (hatched) body.
          Profile: flange (bottom) → body wall → shoulder → neck, with a bore. */}
      <g stroke="currentColor" strokeWidth="1.4" strokeLinejoin="round">
        <path
          d="M120 190 L168 190 L168 176 L150 170 L146 120 L142 70 L132 56 L132 40 L120 40"
          fill="url(#hatch)"
        />
        <path
          d="M120 190 L72 190 L72 176 L90 170 L94 120 L98 70 L108 56 L108 40 L120 40"
          fill="url(#hatch)"
        />
        {/* Bore */}
        <path d="M108 40 L108 70 L112 120 L120 150 L128 120 L132 70 L132 40" fill="none" opacity="0.7" />
      </g>

      {/* Top dimension (Ø flange) — espresso accent line with witness ticks */}
      <g stroke="var(--landing-accent)" strokeWidth="1">
        <line x1="72" y1="206" x2="168" y2="206" />
        <path d="M72 206 l5 -3 M72 206 l5 3" />
        <path d="M168 206 l-5 -3 M168 206 l-5 3" />
        <line x1="72" y1="190" x2="72" y2="210" opacity="0.5" />
        <line x1="168" y1="190" x2="168" y2="210" opacity="0.5" />
      </g>

      {/* Height dimension (right side) — ink */}
      <g stroke="currentColor" strokeWidth="0.9" opacity="0.7">
        <line x1="186" y1="40" x2="186" y2="190" />
        <path d="M186 40 l-3 5 M186 40 l3 5" />
        <path d="M186 190 l-3 -5 M186 190 l3 -5" />
        <line x1="168" y1="40" x2="190" y2="40" opacity="0.5" />
        <line x1="168" y1="190" x2="190" y2="190" opacity="0.5" />
      </g>
    </svg>
  );
}
