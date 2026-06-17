/**
 * ProfileSilhouette — a 2D engineering cross-section of a revolved part.
 *
 * Generated straight from a {@link PartDef}'s section half-profiles: each section
 * is drawn on the right of the axis and mirrored on the left, over a dashed
 * centre-line, so it reads like the sectioned drawing the 3D model is turned
 * from. Serves as the base layer beneath the WebGL viewer and the full fallback
 * when WebGL / motion is unavailable.
 */

import { useMemo } from 'react';
import type { PartDef } from '../three/parts';
import { cn } from '@/lib/cn';

interface ProfileSilhouetteProps {
  part: PartDef;
  className?: string;
}

const W = 260;
const H = 240;
const PAD = 30;

export function ProfileSilhouette({ part, className }: ProfileSilhouetteProps) {
  const { sides, axisX, top, bottom } = useMemo(() => {
    let maxR = 0;
    let minY = Infinity;
    let maxY = -Infinity;
    for (const s of part.sections) {
      for (const p of s.profile) {
        if (p.x > maxR) maxR = p.x;
        if (p.y < minY) minY = p.y;
        if (p.y > maxY) maxY = p.y;
      }
    }
    // Guard degenerate / empty profiles (negative span stays truthy, so `|| 1`
    // alone would not catch it and would yield NaN coordinates).
    if (!Number.isFinite(maxR) || maxR <= 0) maxR = 1;
    const spanY = maxY > minY ? maxY - minY : 1;
    if (!Number.isFinite(minY)) minY = 0;

    // Uniform scale that fits both the diameter (2·maxR) and the height.
    const scale = Math.min((W - PAD * 2) / (maxR * 2), (H - PAD * 2) / spanY);
    const cx = W / 2;
    const mapX = (x: number) => cx + x * scale;
    const mapY = (y: number) => H - PAD - (y - minY) * scale;

    const polylines = part.sections.flatMap((s) => {
      const right = s.profile.map((p) => `${mapX(p.x).toFixed(1)},${mapY(p.y).toFixed(1)}`).join(' ');
      const left = s.profile.map((p) => `${mapX(-p.x).toFixed(1)},${mapY(p.y).toFixed(1)}`).join(' ');
      return [right, left];
    });

    return { sides: polylines, axisX: cx, top: mapY(maxY) - 8, bottom: mapY(minY) + 8 };
  }, [part]);

  return (
    <svg
      viewBox={`0 0 ${W} ${H}`}
      fill="none"
      className={cn('h-full w-full', className)}
      style={{ color: 'var(--landing-ink)' }}
      aria-hidden="true"
    >
      {/* axis of revolution — long-dash / dot centre-line */}
      <line
        x1={axisX}
        y1={top}
        x2={axisX}
        y2={bottom}
        stroke="currentColor"
        strokeWidth="0.8"
        strokeDasharray="10 3 2 3"
        opacity="0.5"
      />
      {sides.map((pts, i) => (
        <polyline
          key={i}
          points={pts}
          stroke="currentColor"
          strokeWidth="1.3"
          strokeLinejoin="round"
          strokeLinecap="round"
          opacity={i % 2 === 0 ? 0.92 : 0.5}
        />
      ))}
    </svg>
  );
}
