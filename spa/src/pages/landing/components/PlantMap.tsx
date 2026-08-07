/**
 * PlantMap — the real location plate for the Filipino-made section.
 *
 * Leaflet + OpenStreetMap (free raster tiles, no API key), desaturated to
 * hold the monochrome paper direction — the map reads as a printed sheet, not
 * a colored webmap. The marker is a divIcon drawn with the DatumMark
 * crosshair convention, so there are no raster assets to break under the
 * bundler and it can carry the design tokens.
 *
 * Loaded lazily from PhilippinesSection: the leaflet chunk never ships in the
 * main bundle. The map is fully explorable — drag, wheel / pinch / button
 * zoom — and every view links out to Google Maps for the full native
 * experience. Wheel-zoom over the map temporarily pauses page scroll while the
 * cursor is on it, which is the expected trade-off for an explorable map.
 */

import { useEffect } from 'react';
import L from 'leaflet';
import { ExternalLink } from 'lucide-react';
import { MapContainer, Marker, Popup, TileLayer, ZoomControl, useMap } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';

export interface PlantMapProps {
  latitude: number;
  longitude: number;
  address: string | null;
}

const OSM_TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

/** Crosshair-target pin — same center-mark language as the brand datum. */
function buildPin(): L.DivIcon {
  return L.divIcon({
    className: 'plant-map-pin',
    html: [
      '<svg width="48" height="48" viewBox="0 0 24 24" fill="none"',
      '  stroke="currentColor" stroke-width="1.4" stroke-linecap="round">',
      '  <circle cx="12" cy="12" r="8.5" opacity="0.55"/>',
      '  <circle cx="12" cy="12" r="4.4"/>',
      '  <line x1="12" y1="1.5" x2="12" y2="7.6"/>',
      '  <line x1="12" y1="16.4" x2="12" y2="22.5"/>',
      '  <line x1="1.5" y1="12" x2="7.6" y2="12"/>',
      '  <line x1="16.4" y1="12" x2="22.5" y2="12"/>',
      '  <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
      '</svg>',
    ].join(''),
    iconSize: [48, 48],
    iconAnchor: [24, 24],
  });
}

/**
 * Leaflet sizes the map against its container at mount; if the container was
 * mid-reveal (transform/visibility) or resized, tiles stay blank. Re-measure
 * once on mount and on every window resize.
 */
function MapSizeKeeper() {
  const map = useMap();

  useEffect(() => {
    const raf = requestAnimationFrame(() => map.invalidateSize());
    const onResize = () => map.invalidateSize();
    window.addEventListener('resize', onResize);
    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener('resize', onResize);
    };
  }, [map]);

  return null;
}

export function PlantMap({ latitude, longitude, address }: PlantMapProps) {
  const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${latitude},${longitude}`)}`;

  return (
    <MapContainer
      className="plant-map h-full w-full"
      center={[latitude, longitude]}
      zoom={16}
      zoomControl={false}
      attributionControl
    >
      <TileLayer
        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        url={OSM_TILE_URL}
        maxZoom={19}
      />
      <ZoomControl position="bottomright" />
      <Marker position={[latitude, longitude]} icon={buildPin()}>
        <Popup>
          <div className="space-y-0.5">
            <div className="font-mono text-[10px] uppercase tracking-[0.16em] text-accent">
              Ogami · plant
            </div>
            <div className="font-sans text-[12px] leading-snug text-primary">
              {address ?? 'First Cavite Industrial Estate, Dasmariñas, Cavite'}
            </div>
            <a
              href={mapsUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-1.5 inline-flex items-center gap-1 font-mono text-[10px] uppercase tracking-[0.12em] text-accent underline underline-offset-2 transition-opacity hover:opacity-70"
            >
              <ExternalLink size={11} />
              Open in Google Maps
            </a>
          </div>
        </Popup>
      </Marker>
      <MapSizeKeeper />
    </MapContainer>
  );
}
