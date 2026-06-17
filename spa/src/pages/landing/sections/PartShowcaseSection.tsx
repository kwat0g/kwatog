/**
 * PartShowcaseSection — "inspect the part."
 *
 * A live 3D drawing frame the visitor can drive: pick one of several molded
 * parts, drag to rotate it, and pull it apart into an engineering exploded view.
 * Left rail carries the part selector, a decoding spec readout, and the
 * controls; the right frame holds the WebGL model over its ghosted cross-section.
 *
 * Reduced-motion / no-WebGL: the frame falls back to the static cross-section
 * and the interactive controls are hidden — the section still reads as a precise
 * parts catalogue.
 */

import { useMemo, useState } from 'react';
import { Layers, Box, Hand, RotateCcw } from 'lucide-react';
import { SectionHeading } from '../components/SectionHeading';
import { ScrambleText } from '../components/ScrambleText';
import { ProfileSilhouette } from '../components/ProfileSilhouette';
import { PartShowcase3D } from '../three/PartShowcase3D';
import { PARTS } from '../three/parts';
import { reduceMotion } from '../motion';
import { cn } from '@/lib/cn';

export function PartShowcaseSection() {
  const [partIndex, setPartIndex] = useState(0);
  const [exploded, setExploded] = useState(false);
  const motionOK = useMemo(() => !reduceMotion(), []);
  const part = PARTS[partIndex];

  return (
    <section id="parts-3d" className="relative bg-landing-canvas px-5 py-24 sm:px-8 sm:py-32">
      <div className="mx-auto max-w-7xl">
        <SectionHeading
          eyebrow="Inspect the part"
          title={
            <>
              Turn it over.{' '}
              <span className="text-landing-accent">Take it apart.</span>
            </>
          }
          intro="Every part we mold is a controlled geometry. Spin one, or pull it into an exploded view — the same way our engineers inspect a section before a single shot is run."
        />

        <div className="mt-16 grid items-stretch gap-8 lg:grid-cols-[0.82fr_1.18fr] lg:gap-12">
          {/* ── Control rail ─────────────────────────────────────── */}
          <div data-reveal="left" className="flex flex-col">
            {/* part selector */}
            <div role="tablist" aria-label="Select a part" className="flex flex-wrap gap-2">
              {PARTS.map((p, i) => {
                const active = i === partIndex;
                return (
                  <button
                    key={p.id}
                    type="button"
                    role="tab"
                    aria-selected={active}
                    onClick={() => setPartIndex(i)}
                    className={cn(
                      'rounded-full border px-4 py-2 font-mono text-[11px] uppercase tracking-[0.12em] transition-colors duration-300',
                      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas',
                      active
                        ? 'border-landing-accent bg-landing-accent text-landing-accent-fg'
                        : 'border-landing-border text-landing-muted hover:border-landing-accent/40 hover:text-landing-text',
                    )}
                  >
                    {p.name}
                  </button>
                );
              })}
            </div>

            {/* decoding spec readout */}
            <dl className="mt-8 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-landing-border bg-landing-border">
              {[
                { k: 'Material', v: part.material },
                { k: 'Tolerance', v: part.tolerance },
                { k: 'Feature', v: part.feature },
                { k: 'Application', v: part.application },
              ].map((row) => (
                <div key={row.k} className="bg-landing-surface px-5 py-4">
                  <dt className="font-mono text-[10px] uppercase tracking-[0.16em] text-landing-subtle-text">
                    {row.k}
                  </dt>
                  <dd className="mt-1.5 font-mono text-[13px] text-landing-text">
                    {/* key by part → re-decode on every part change */}
                    <ScrambleText key={`${part.id}-${row.k}`} text={row.v} trigger="mount" />
                  </dd>
                </div>
              ))}
            </dl>

            {/* construction (section stack) */}
            <div className="mt-5 flex flex-wrap items-center gap-x-2 gap-y-1.5 font-mono text-[11px] text-landing-muted">
              <span className="text-landing-subtle-text">Construction</span>
              {part.sections.map((s, i) => (
                <span key={s.label ?? i} className="flex items-center gap-2">
                  {i > 0 && <span className="text-landing-accent/40">+</span>}
                  <span className="text-landing-text-secondary">{s.label}</span>
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
                    'group inline-flex items-center gap-2.5 rounded-full border px-5 py-3 font-sans text-[13px] font-medium transition-colors duration-300',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas',
                    exploded
                      ? 'border-landing-accent bg-landing-accent text-landing-accent-fg'
                      : 'border-landing-border-strong text-landing-text hover:border-landing-accent/50 hover:bg-landing-elevated',
                  )}
                >
                  {exploded ? <Box size={15} /> : <Layers size={15} />}
                  {exploded ? 'Assemble part' : 'Exploded view'}
                </button>

                <p className="mt-4 flex items-center gap-4 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-subtle-text">
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
              <p className="mt-auto pt-8 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-subtle-text">
                Static cross-section shown
              </p>
            )}
          </div>

          {/* ── Drawing frame ────────────────────────────────────── */}
          <div data-reveal="right">
            <figure className="relative aspect-square w-full overflow-hidden rounded-xl border border-landing-border-strong bg-landing-surface sm:aspect-[5/4] lg:aspect-square">
              {/* blueprint grid */}
              <div
                aria-hidden="true"
                className="absolute inset-0"
                style={{
                  backgroundImage:
                    'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
                    'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
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
                  className={`absolute h-4 w-4 border-landing-border-strong ${pos}`}
                />
              ))}

              {/* ghosted cross-section base (full when no WebGL) */}
              <div className="absolute inset-0 flex items-center justify-center p-12">
                <ProfileSilhouette
                  part={part}
                  className={motionOK ? 'opacity-[0.28]' : 'opacity-90'}
                />
              </div>

              {/* live 3D model */}
              {motionOK && <PartShowcase3D part={part} exploded={exploded} />}

              {/* dimension callouts */}
              <span className="absolute left-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-accent">
                REV · A
              </span>
              <span className="absolute right-5 top-5 font-mono text-[10px] uppercase tracking-[0.16em] text-landing-accent">
                {part.tolerance}
              </span>

              {/* title block */}
              <figcaption className="absolute inset-x-3 bottom-3 grid grid-cols-3 overflow-hidden rounded-md border border-landing-border bg-landing-canvas/85 font-mono text-[9px] uppercase tracking-[0.12em] text-landing-muted backdrop-blur-sm sm:text-[10px]">
                <span className="border-r border-landing-border px-3 py-2">
                  <span className="block text-landing-subtle-text">Part</span>
                  <span className="text-landing-text">{part.name}</span>
                </span>
                <span className="border-r border-landing-border px-3 py-2">
                  <span className="block text-landing-subtle-text">Material</span>
                  <span className="text-landing-text">{part.material}</span>
                </span>
                <span className="px-3 py-2">
                  <span className="block text-landing-subtle-text">{exploded ? 'View' : 'Std'}</span>
                  <span className="text-landing-text">{exploded ? 'Exploded' : 'IATF 16949'}</span>
                </span>
              </figcaption>
            </figure>
          </div>
        </div>
      </div>
    </section>
  );
}
