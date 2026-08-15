/**
 * PhilippinesSection — Filipino identity, carried by words and place.
 *
 * Per the monochrome direction, national identity is textual and grounded:
 * locally engineered precision manufacturing section — no flag, no sun. The
 * visual is a real location plate: a live OpenStreetMap of the plant in
 * Dasmariñas, desaturated to paper, with a crosshair datum over the factory,
 * coordinate readouts in the corners, and the address beneath. The map is
 * lazy-loaded so the leaflet chunk never ships in the main bundle.
 */

import { Suspense, lazy } from 'react';
import { useQuery } from '@tanstack/react-query';
import { LuExternalLink, LuMapPin } from '@/lib/icons';
import { landingApi } from '@/api/landing';

const PlantMap = lazy(() =>
  import('../components/PlantMap').then((m) => ({ default: m.PlantMap })),
);

const GRID_FALLBACK: React.CSSProperties = {
  backgroundImage:
    'linear-gradient(var(--blueprint-grid) 1px, transparent 1px),' +
    'linear-gradient(90deg, var(--blueprint-grid) 1px, transparent 1px)',
  backgroundSize: '32px 32px',
};

export function PhilippinesSection() {
  const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const points = content?.philippines_points ?? [];
  const copy = content?.philippines_copy;
  const copyBody = (copy?.body ?? '').replace('{{company}}', contact?.legal_name ?? '—');

  const contactLat = contact?.latitude ?? null;
  const contactLon = contact?.longitude ?? null;
  const hasCoordinates =
    typeof contactLat === 'number' && typeof contactLon === 'number';
  const region = (contact?.address ?? '')
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
    .slice(-3, -1)
    .join(' · ');
  const mapsUrl = hasCoordinates
    ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${contactLat},${contactLon}`)}`
    : null;

  return (
    <section
      id="filipino-made"
      className="relative overflow-hidden bg-surface px-5 sm:px-5 py-20 sm:py-28"
    >
      <div className="mx-auto grid max-w-[1440px] items-center gap-14 lg:grid-cols-2 lg:gap-20">
        {/* Copy */}
        <div data-reveal="left">
          <div data-reveal className="flex items-center gap-3">
            <span className="h-0.5 w-8 bg-accent" />
            <span className="font-mono text-[11px] uppercase tracking-[0.24em] text-accent">
              {copy?.eyebrow ?? '—'}
            </span>
          </div>

          <h2
            data-reveal
            data-reveal-delay="0.05"
            className="mt-6 font-display text-[clamp(2.5rem,5vw,4.5rem)] font-semibold leading-[0.98] tracking-[-0.03em] text-primary"
          >
            {copy?.title ?? '—'}
          </h2>

          <p
            data-reveal
            data-reveal-delay="0.1"
            className="mt-6 max-w-xl font-sans text-base font-light tracking-wide leading-relaxed text-secondary sm:text-lg"
          >
            {copyBody || '—'}
          </p>

          <dl className="mt-10 space-y-5">
            {points.map((p, i) => (
              <div
                key={`${p.value}-${p.label}`}
                data-reveal
                data-reveal-delay={(0.12 + i * 0.06).toFixed(2)}
                className="group flex items-baseline gap-6 border-t border-default pt-6 transition-colors duration-300 hover:border-accent"
              >
                <dt className="w-24 shrink-0 font-display text-4xl font-semibold tracking-tight text-accent transition-transform duration-500 group-hover:-translate-y-1">
                  {p.value}
                </dt>
                <dd className="font-sans text-base font-light leading-relaxed text-secondary">
                  {p.label}
                </dd>
              </div>
            ))}
          </dl>
        </div>

        {/* Visual — live location plate */}
        <div data-reveal="right" data-reveal-delay="0.1" className="relative">
          <figure className="relative mx-auto aspect-square w-full max-w-[500px] overflow-hidden rounded-2xl border border-strong bg-canvas shadow-[0_20px_60px_-15px_rgba(0,0,0,0.1)] transition-transform duration-700 hover:scale-[1.02]">
            {/* The real map — lazy chunk; blueprint grid as the load placeholder */}
            <Suspense
              fallback={<div aria-hidden="true" className="absolute inset-0" style={GRID_FALLBACK} />}
            >
              {hasCoordinates ? (
                <div className="absolute inset-0 isolate">
                  <PlantMap
                    latitude={contactLat}
                    longitude={contactLon}
                    address={contact?.address ?? null}
                  />
                </div>
              ) : (
                <div aria-hidden="true" className="absolute inset-0" style={GRID_FALLBACK} />
              )}
            </Suspense>

            {/* coordinate readouts — instrument chips over the paper map.
                Solid paper backgrounds: color-mix alpha fails on some engines,
                leaving floating text over the tiles. */}
            <span className="absolute left-5 top-5 z-10 rounded-[3px] border border-default bg-canvas px-1.5 py-1 font-mono text-[10px] uppercase tracking-[0.18em] text-muted">
                {hasCoordinates ? `${Math.abs(contactLat).toFixed(4)}° ${contactLat >= 0 ? 'N' : 'S'}` : '—'}
            </span>
            <span className="absolute right-5 top-5 z-10 rounded-[3px] border border-default bg-canvas px-1.5 py-1 font-mono text-[10px] uppercase tracking-[0.18em] text-muted">
                {hasCoordinates ? `${Math.abs(contactLon).toFixed(4)}° ${contactLon >= 0 ? 'E' : 'W'}` : '—'}
            </span>

            {/* open in Google Maps — full native experience, new tab */}
            {mapsUrl ? (
              <a
                href={mapsUrl}
                target="_blank"
                rel="noopener noreferrer"
                title="Open the plant location in Google Maps"
                className="absolute left-1/2 top-14 z-10 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-[3px] border border-strong bg-canvas px-2.5 py-1.5 font-mono text-[10px] uppercase tracking-[0.14em] text-primary transition-colors duration-200 hover:border-accent hover:text-accent sm:top-5"
              >
                <LuExternalLink size={12} className="text-accent" />
                Open in Google Maps
              </a>
            ) : null}

            {/* location label */}
            <div className="absolute left-1/2 top-[calc(50%+64px)] z-10 w-[86%] -translate-x-1/2 text-center">
              <span className="inline-flex max-w-full items-center justify-center gap-1.5 rounded-[3px] border border-default bg-canvas px-2 py-1 text-center font-mono text-[11px] uppercase leading-snug tracking-[0.16em] text-primary">
                <LuMapPin size={13} className="shrink-0 text-accent" />
                {contact?.address ?? '—'}
              </span>
            </div>

            <span className="absolute bottom-8 left-5 z-10 rounded-[3px] border border-default bg-canvas px-1.5 py-1 font-mono text-[10px] uppercase tracking-[0.18em] text-muted">
              {region || '—'}
            </span>
            <span className="absolute bottom-5 left-5 z-10 font-mono text-[10px] uppercase tracking-[0.18em] text-text-subtle">
              Plant · datum
            </span>
          </figure>
        </div>
      </div>
    </section>
  );
}
