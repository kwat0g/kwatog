/**
 * AutoPartShowcase — a self-driving tour of the parts Ogami molds.
 *
 * Geometry is parametric, authored in three/parts.ts — not imported CAD. Visible
 * copy must not imply otherwise.
 *
 * Cycles through every {@link PartDef} on a timer: it dwells on the assembled
 * part (info on screen), pulls it into an exploded view for a couple of seconds,
 * reassembles, then cross-fades to the next part. A timer bar and clickable dots
 * track progress; the spec readout re-decodes on each change. Built for the auth
 * brand panel, where it runs unattended.
 *
 * Reduced-motion / no-WebGL: PartShowcase3D renders nothing, so the static
 * ProfileSilhouette + spec readout show instead, and the auto-timer is disabled
 * (the dots still let a visitor step through parts by hand).
 */

import { useEffect, useRef, useState } from 'react';
import { PartShowcase3D } from '../three/PartShowcase3D';
import { PARTS } from '../three/parts';
import { ProfileSilhouette } from './ProfileSilhouette';
import { ScrambleText } from './ScrambleText';
import { reduceMotion } from '../motion';
import { cn } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { landingApi } from '@/api/landing';

// Per-part timeline (ms): dwell assembled → hold exploded → reassemble → next.
const DWELL = 2600;
const EXPLODE = 2200;
const REASSEMBLE = 1000;
const CYCLE = DWELL + EXPLODE + REASSEMBLE;

/** A linear timer bar that refills each time `index` changes. */
function CycleBar({ index, active }: { index: number; active: boolean }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el || !active) return;
    el.style.transition = 'none';
    el.style.transform = 'scaleX(0)';
    void el.offsetWidth; // force reflow so the restart is honored
    el.style.transition = `transform ${CYCLE}ms linear`;
    el.style.transform = 'scaleX(1)';
  }, [index, active]);
  return (
    <div
      ref={ref}
      aria-hidden="true"
      className="h-px w-full origin-left bg-accent/60"
      style={{ transform: 'scaleX(0)' }}
    />
  );
}

interface AutoPartShowcaseProps {
  className?: string;
}

export function AutoPartShowcase({ className }: AutoPartShowcaseProps) {
  const [index, setIndex] = useState(0);
  const [exploded, setExploded] = useState(false);
  // Run the live 3D + auto-timer only with motion allowed AND at lg+ (the auth
  // brand panel is `hidden` below lg, so mounting a WebGL context there would
  // spin one up for an element that never paints).
  const motionOK = useRef(
    !reduceMotion() &&
      typeof window !== 'undefined' &&
      window.matchMedia('(min-width: 1024px)').matches,
  ).current;
  const part = PARTS[index];
  const { data: content } = useQuery({
    queryKey: ['landing', 'content'],
    queryFn: landingApi.content,
    staleTime: 300_000,
  });
  const liveSpec = content?.part_specs?.find((candidate) => candidate.id === part.id);
  const displayPart = liveSpec ? { ...part, ...liveSpec } : part;

  // Drive the per-part timeline; re-runs whenever `index` changes.
  useEffect(() => {
    if (!motionOK) return;
    setExploded(false);
    const t1 = window.setTimeout(() => setExploded(true), DWELL);
    const t2 = window.setTimeout(() => setExploded(false), DWELL + EXPLODE);
    const t3 = window.setTimeout(() => setIndex((i) => (i + 1) % PARTS.length), CYCLE);
    return () => {
      clearTimeout(t1);
      clearTimeout(t2);
      clearTimeout(t3);
    };
  }, [index, motionOK]);

  return (
    <div className={cn('relative h-full w-full', className)}>
      {/* timer bar */}
      {motionOK && (
        <div className="absolute inset-x-0 top-0 z-20 h-px overflow-hidden">
          <CycleBar index={index} active={motionOK} />
        </div>
      )}

      {/* ghosted cross-section base */}
      <div className="absolute inset-0 flex items-center justify-center p-5">
        <ProfileSilhouette
          part={displayPart}
          className={motionOK ? 'opacity-[0.28]' : 'opacity-90'}
        />
      </div>

      {/* live 3D model */}
      {motionOK && <PartShowcase3D part={displayPart} exploded={exploded} />}

      {/* live dimension callout */}
      <span className="absolute right-5 top-5 z-20 font-mono text-[10px] uppercase tracking-[0.16em] text-accent">
        <ScrambleText key={`tol-${part.id}`} text={displayPart.tolerance || '—'} trigger="mount" />
      </span>

      {/* progress dots — also let a visitor step through by hand */}
      <div className="absolute inset-x-0 bottom-[4.25rem] z-20 flex items-center justify-center gap-2">
        {PARTS.map((p, i) => (
          <button
            key={p.id}
            type="button"
            aria-label={`Show ${content?.part_specs?.find((candidate) => candidate.id === p.id)?.name || p.id}`}
            aria-current={i === index}
            onClick={() => setIndex(i)}
            className={cn(
              'h-1.5 rounded-full transition-[width] duration-300',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface',
              i === index ? 'w-5 bg-accent' : 'w-1.5 bg-strong hover:bg-muted',
            )}
          />
        ))}
      </div>

      {/* title block — live spec readout */}
      <div className="absolute inset-x-3 bottom-3 z-20 grid grid-cols-3 overflow-hidden rounded-md border border-default bg-canvas font-mono text-[9px] uppercase tracking-[0.12em] text-muted sm:text-[10px]">
        <span className="border-r border-default px-3 py-2">
          <span className="block text-text-subtle">Part</span>
          <span className="block truncate text-primary">
            <ScrambleText key={`name-${part.id}`} text={displayPart.name || '—'} trigger="mount" />
          </span>
        </span>
        <span className="border-r border-default px-3 py-2">
          <span className="block text-text-subtle">Material</span>
          <span className="block truncate text-primary">
            <ScrambleText
              key={`mat-${part.id}`}
              text={displayPart.material || '—'}
              trigger="mount"
            />
          </span>
        </span>
        <span className="px-3 py-2">
          <span className="block text-text-subtle">View</span>
          <span className="block text-primary">{exploded ? 'Exploded' : 'Assembled'}</span>
        </span>
      </div>
    </div>
  );
}
