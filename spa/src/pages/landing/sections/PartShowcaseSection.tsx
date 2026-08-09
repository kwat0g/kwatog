/**
 * PartShowcaseSection — an illustrated gallery of parts Ogami molds.
 *
 * Pick one of several molded parts, drag to rotate it, and pull it apart to see
 * how it is built up. Left rail carries the part selector, a decoding spec
 * readout, and the controls; the right frame holds the model over its ghosted
 * cross-section.
 *
 * The geometry is a parametric approximation authored in three/parts.ts — lathed
 * from hand-written half-profiles, NOT imported CAD. Copy here must not claim
 * otherwise: no "CAD", no "catalogue", no "inspect". Ogami molds to a customer's
 * existing tooling and does not take custom part design, so this section is
 * illustration of our own products, not an intake tool.
 *
 * Reduced-motion / no-WebGL: the frame falls back to the static cross-section
 * and the interactive controls are hidden.
 */

import { useEffect, useMemo, useState } from 'react';
import { Layers, Box, Hand, RotateCcw } from 'lucide-react';
import { SectionHeading } from '../components/SectionHeading';
import { ScrambleText } from '../components/ScrambleText';
import { ProfileSilhouette } from '../components/ProfileSilhouette';
import { PartShowcase3D } from '../three/PartShowcase3D';
import { PARTS } from '../three/parts';
import { reduceMotion } from '../motion';
import { cn } from '@/lib/cn';
import { landingApi } from '@/api/landing';
import { useQuery } from '@tanstack/react-query';

export function PartShowcaseSection() {
  const [partIndex, setPartIndex] = useState(0);
  const [exploded, setExploded] = useState(false);
  const motionOK = useMemo(() => !reduceMotion(), []);
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const qualityStandard = content?.quality_policy?.standard ?? content?.quality_methods?.[0] ?? '—';
  const geometryById = useMemo(() => new Map(PARTS.map((candidate) => [candidate.id, candidate])), []);
  // The API owns the public part catalogue. Local geometry is only a renderer
  // implementation detail; a new/removed ERP part must update this rail without
  // requiring a frontend data edit.
  const liveParts = useMemo(
    () => (content?.part_specs ?? [])
      .map((spec) => {
        const geometry = geometryById.get(spec.id);
        return geometry ? { ...geometry, ...spec } : null;
      })
      .filter((part): part is NonNullable<typeof part> => part !== null),
    [content?.part_specs, geometryById],
  );
  const part = liveParts[partIndex];

  useEffect(() => {
    setPartIndex((current) => Math.min(current, Math.max(liveParts.length - 1, 0)));
  }, [liveParts.length]);

  return (
    <section id="parts-3d" className="relative bg-canvas px-5 py-24 sm:px-5 sm:py-32">
      <div className="mx-auto max-w-[1440px]">
        <SectionHeading
          eyebrow={content?.section_copy?.part_showcase_eyebrow ?? '—'}
          title={content?.section_copy?.part_showcase_title ?? '—'}
          intro={content?.section_copy?.part_showcase_intro ?? '—'}
        />

        <div className="mt-16 grid items-stretch gap-5 lg:grid-cols-[0.82fr_1.18fr] lg:gap-12">
          {/* ── Control rail ─────────────────────────────────────── */}
          <div data-reveal="left" className="flex flex-col">
            {/* part selector */}
            <div role="tablist" aria-label="Select a part" className="flex flex-wrap gap-2">
              {liveParts.map((p, i) => {
                const active = i === partIndex;
                return (
                  <button
                    key={p.id}
                    type="button"
                    role="tab"
                    aria-selected={active}
                    onClick={() => setPartIndex(i)}
                    className={cn(
                      'rounded-full border px-5 py-2.5 font-mono text-xs uppercase tracking-[0.15em] transition-colors duration-150',
                      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas',
                      active
                        ? 'border-accent bg-accent text-accent-fg'
                        : 'border-default text-muted hover:border-accent/40 hover:text-primary',
                    )}
                  >
                    {p.name || '—'}
                  </button>
                );
              })}
            </div>

            {/* decoding spec readout */}
            <dl className="mt-8 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-default bg-border-default">
              {[
                { k: 'Material', v: part?.material || '—' },
                { k: 'Tolerance', v: part?.tolerance || '—' },
                { k: 'Feature', v: part?.feature || '—' },
                { k: 'Application', v: part?.application || '—' },
              ].map((row) => (
                <div key={row.k} className="bg-surface px-5 py-4">
                  <dt className="font-mono text-[10px] uppercase tracking-[0.16em] text-text-subtle">
                    {row.k}
                  </dt>
                  <dd className="mt-1.5 font-mono text-[13px] text-primary">
                    {/* key by part → re-decode on every part change */}
                    <ScrambleText key={`${part?.id ?? 'empty'}-${row.k}`} text={row.v} trigger="mount" />
                  </dd>
                </div>
              ))}
            </dl>

            {/* construction (section stack) */}
            <div className="mt-5 flex flex-wrap items-center gap-x-2 gap-y-1.5 font-mono text-[11px] text-muted">
              <span className="text-text-subtle">Construction</span>
              {(part?.sections ?? []).map((s, i) => (
                <span key={s.label ?? i} className="flex items-center gap-2">
                  {i > 0 && <span className="text-accent/40">+</span>}
                  <span className="text-secondary">{s.label}</span>
                </span>
              ))}
            </div>

            {/* controls */}
            {motionOK ? (
              <div className="mt-auto pt-8">
                <button
                  type="button"
                  aria-pressed={exploded}
                  onClick={() => setExploded((v) => !v)}
                  className={cn(
                    'group inline-flex items-center gap-3 rounded-full border px-6 py-3.5 font-sans text-md font-semibold transition-colors duration-150',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas',
                    exploded
                      ? 'border-accent bg-accent text-accent-fg'
                      : 'border-strong text-primary hover:border-accent/50 hover:bg-elevated',
                  )}
                >
                  {exploded ? <Box size={15} /> : <Layers size={15} />}
                  {exploded ? 'Assemble part' : 'Exploded view'}
                </button>

                <p className="mt-4 flex items-center gap-4 font-mono text-[10px] uppercase tracking-[0.16em] text-text-subtle">
                  <span className="flex items-center gap-1.5">
                    <Hand size={12} />
                    Drag to rotate
                  </span>
                  <span className="flex items-center gap-1.5">
                    <RotateCcw size={12} />
                    Auto-turntable
                  </span>
                </p>
              </div>
            ) : (
              <p className="mt-auto pt-8 font-mono text-[10px] uppercase tracking-[0.16em] text-text-subtle">
                Static cross-section shown
              </p>
            )}
          </div>

          {/* ── Drawing frame ────────────────────────────────────── */}
          <div data-reveal="right" className="flex items-center">
            <figure className="relative w-full overflow-hidden rounded-md border border-strong bg-surface aspect-square sm:aspect-[4/3] lg:aspect-[4/3]">
              {/* blueprint grid */}
              <div
                aria-hidden="true"
                className="absolute inset-0"
                style={{
                  backgroundImage:
                    'linear-gradient(var(--blueprint-grid) 1px, transparent 1px),' +
                    'linear-gradient(90deg, var(--blueprint-grid) 1px, transparent 1px)',
                  backgroundSize: '32px 32px',
                  maskImage: 'radial-gradient(120% 100% at 50% 50%, #000 40%, transparent 92%)',
                  WebkitMaskImage: 'radial-gradient(120% 100% at 50% 50%, #000 40%, transparent 92%)',
                }}
              />

              {/* corner registration marks */}
              {[
                'left-3 top-3 border-l border-t',
                'right-3 top-3 border-r border-t',
                'left-3 bottom-3 border-b border-l',
                'right-3 bottom-3 border-b border-r',
              ].map((pos) => (
                <span
                  key={pos}
                  aria-hidden="true"
                  className={`absolute h-4 w-4 border-strong ${pos}`}
                />
              ))}

              {/* ghosted cross-section base (full when no WebGL) */}
              <div className="absolute inset-0 flex items-center justify-center p-12">
                {part && (
                  <ProfileSilhouette
                    part={part}
                    className={motionOK ? 'opacity-[0.28]' : 'opacity-90'}
                  />
                )}
              </div>

              {/* live 3D model */}
              {motionOK && part && <PartShowcase3D part={part} exploded={exploded} />}

              {/* dimension callouts */}
              <span className="absolute left-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-accent">
                REV · A
              </span>
              <span className="absolute right-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-accent">
                {part?.tolerance || '—'}
              </span>

              {/* title block */}
              <figcaption className="absolute inset-x-3 bottom-3 grid grid-cols-3 overflow-hidden rounded-md border border-default bg-canvas font-mono text-[9px] uppercase tracking-[0.12em] text-muted sm:text-2xs">
                <span className="border-r border-default px-3 py-2">
                  <span className="block text-text-subtle">Part</span>
                  <span className="text-primary">{part?.name || '—'}</span>
                </span>
                <span className="border-r border-default px-3 py-2">
                  <span className="block text-text-subtle">Material</span>
                  <span className="text-primary">{part?.material || '—'}</span>
                </span>
                <span className="px-3 py-2">
                  <span className="block text-text-subtle">{exploded ? 'View' : 'Std'}</span>
                  <span className="text-primary">{exploded ? 'Exploded' : qualityStandard}</span>
                </span>
              </figcaption>
            </figure>
          </div>
        </div>
      </div>
    </section>
  );
}
