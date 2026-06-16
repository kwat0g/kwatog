/**
 * PartBlueprint — static engineering drawing of an automotive oil filler cap.
 *
 * Serves as the base layer inside the hero drawing frame and the full fallback
 * when WebGL/motion is unavailable. The profile shows a rotationally symmetric
 * cap: seal flange, threaded/grip skirt, top deck, and internal bore — the kind
 * of part Ogami molds for engine covers.
 */

import { cn } from '@/lib/cn';

interface PartBlueprintProps {
  className?: string;
}

export function PartBlueprint({ className }: PartBlueprintProps) {
  return (
    <svg
      viewBox="0 0 260 240"
      fill="none"
      className={cn('h-full w-full', className)}
      style={{ color: 'var(--landing-ink)' }}
      aria-hidden="true"
    >
      <defs>
        <pattern
          id="hatch"
          width="5"
          height="5"
          patternUnits="userSpaceOnUse"
          patternTransform="rotate(45)"
        >
          <line x1="0" y1="0" x2="0" y2="5" stroke="currentColor" strokeWidth="0.5" opacity="0.28" />
        </pattern>
      </defs>

      {/* Vertical centerline (axis of revolution) — long-dash / dot */}
      <line
        x1="130"
        y1="20"
        x2="130"
        y2="220"
        stroke="currentColor"
        strokeWidth="0.8"
        strokeDasharray="10 3 2 3"
        opacity="0.55"
      />

      {/* Right half — sectioned cap body */}
      <g stroke="currentColor" strokeWidth="1.3" strokeLinejoin="round">
        <path
          d="M130 188 L186 188 L186 176 L180 172 L164 168 L164 120 L164 80 L168 70 L160 66 L150 66 L142 70 L146 80 L146 120 L146 156 L130 156 Z"
          fill="url(#hatch)"
        />
        {/* Grip rings on skirt */}
        <path d="M164 150 L158 150 M164 140 L158 140 M164 130 L158 130 M164 110 L158 110 M164 100 L158 100" opacity="0.8" />
      </g>

      {/* Left half — sectioned cap body */}
      <g stroke="currentColor" strokeWidth="1.3" strokeLinejoin="round">
        <path
          d="M130 188 L74 188 L74 176 L80 172 L96 168 L96 120 L96 80 L92 70 L100 66 L110 66 L118 70 L114 80 L114 120 L114 156 L130 156 Z"
          fill="url(#hatch)"
        />
        {/* Grip rings on skirt */}
        <path d="M96 150 L102 150 M96 140 L102 140 M96 130 L102 130 M96 110 L102 110 M96 100 L102 100" opacity="0.8" />
      </g>

      {/* Internal bore / hollow cap */}
      <path
        d="M114 80 L114 120 L114 156 L118 166 L122 170 L130 170 M146 80 L146 120 L146 156 L142 166 L138 170 L130 170"
        stroke="currentColor"
        strokeWidth="1"
        fill="none"
        opacity="0.75"
      />

      {/* Top deck detail */}
      <ellipse
        cx="130"
        cy="70"
        rx="20"
        ry="5"
        stroke="currentColor"
        strokeWidth="0.9"
        fill="none"
        opacity="0.6"
      />

      {/* Flange dimension — espresso accent */}
      <g stroke="var(--landing-accent)" strokeWidth="1">
        <line x1="74" y1="200" x2="186" y2="200" />
        <path d="M74 200 l5 -3 M74 200 l5 3" />
        <path d="M186 200 l-5 -3 M186 200 l-5 3" />
        <line x1="74" y1="188" x2="74" y2="204" opacity="0.5" />
        <line x1="186" y1="188" x2="186" y2="204" opacity="0.5" />
      </g>

      {/* Height dimension — ink */}
      <g stroke="currentColor" strokeWidth="0.9" opacity="0.7">
        <line x1="202" y1="66" x2="202" y2="188" />
        <path d="M202 66 l-3 5 M202 66 l3 5" />
        <path d="M202 188 l-3 -5 M202 188 l3 -5" />
        <line x1="186" y1="66" x2="206" y2="66" opacity="0.5" />
        <line x1="186" y1="188" x2="206" y2="188" opacity="0.5" />
      </g>
    </svg>
  );
}
