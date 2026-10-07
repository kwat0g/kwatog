/**
 * Leaflet half of the plant location picker. Kept in its own module so the
 * leaflet chunk is lazy-loaded and never ships with the Settings page itself.
 *
 * Pure view: it renders the pin and the allowed circle it is given and reports
 * clicks/drags back through `onPick`. All validation and state live in
 * PlantLocationPicker.
 */
import { useEffect } from 'react';
import L from 'leaflet';
import {
  Circle,
  MapContainer,
  Marker,
  TileLayer,
  ZoomControl,
  useMap,
  useMapEvents,
} from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import './plant-location-map.css';

export interface PlantLocationMapProps {
  /** Null while the draft is invalid — the map then shows neither pin nor circle. */
  center: { lat: number; lng: number } | null;
  radius: number | null;
  editable: boolean;
  /** Bump to re-frame the map on the circle (GPS jump, paste, reset, save). */
  viewNonce: number;
  onPick: (lat: number, lng: number) => void;
}

const OSM_TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
/** Only used to draw something when no valid location exists yet (Dasmariñas, Cavite). */
const FALLBACK_CENTER: [number, number] = [14.286, 120.9345];

const PIN = L.divIcon({
  className: 'plant-location-pin',
  html: [
    '<svg width="44" height="44" viewBox="0 0 24 24" fill="none"',
    '  stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">',
    '  <circle cx="12" cy="12" r="8.5" opacity="0.55"/>',
    '  <circle cx="12" cy="12" r="4.2"/>',
    '  <line x1="12" y1="1.5" x2="12" y2="7.6"/>',
    '  <line x1="12" y1="16.4" x2="12" y2="22.5"/>',
    '  <line x1="1.5" y1="12" x2="7.6" y2="12"/>',
    '  <line x1="16.4" y1="12" x2="22.5" y2="12"/>',
    '  <circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/>',
    '</svg>',
  ].join(''),
  iconSize: [44, 44],
  iconAnchor: [22, 22],
});

/** Square around the circle, in metres (a detached L.Circle cannot report bounds: it needs a map to project). */
function circleBounds(center: { lat: number; lng: number }, radius: number): L.LatLngBounds {
  return L.latLng(center.lat, center.lng).toBounds(radius * 2);
}

/** Re-measures after reveal/resize (blank-tile bug) and keeps the circle in view. */
function ViewKeeper({
  center,
  radius,
  viewNonce,
}: Pick<PlantLocationMapProps, 'center' | 'radius' | 'viewNonce'>) {
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

  // Explicit re-frame: first paint and every deliberate location jump.
  useEffect(() => {
    if (!center || !radius) return;
    map.fitBounds(circleBounds(center, radius), { padding: [56, 56], animate: false, maxZoom: 17 });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- re-frame only on a deliberate jump, not while dragging
  }, [viewNonce, map]);

  // Growing the radius must never push the edge off-screen.
  useEffect(() => {
    if (!center || !radius) return;
    const bounds = circleBounds(center, radius);
    if (!map.getBounds().contains(bounds)) {
      map.fitBounds(bounds, { padding: [56, 56], animate: false, maxZoom: 17 });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only the radius should trigger this
  }, [radius, map]);

  return null;
}

function ClickToPlace({ editable, onPick }: Pick<PlantLocationMapProps, 'editable' | 'onPick'>) {
  useMapEvents({
    click(e) {
      if (editable) onPick(e.latlng.lat, e.latlng.lng);
    },
  });

  return null;
}

export default function PlantLocationMap({
  center,
  radius,
  editable,
  viewNonce,
  onPick,
}: PlantLocationMapProps) {
  return (
    <MapContainer
      className={`plant-location-map h-full w-full${editable ? ' is-editable' : ''}`}
      center={center ? [center.lat, center.lng] : FALLBACK_CENTER}
      zoom={16}
      scrollWheelZoom={false}
      zoomControl={false}
      attributionControl
    >
      <TileLayer
        url={OSM_TILE_URL}
        maxZoom={19}
        attribution='&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>'
      />
      <ZoomControl position="topright" />
      <ClickToPlace editable={editable} onPick={onPick} />
      <ViewKeeper center={center} radius={radius} viewNonce={viewNonce} />
      {center && radius ? (
        <>
          <Circle
            center={[center.lat, center.lng]}
            radius={radius}
            pathOptions={{ className: 'plant-location-circle' }}
            interactive={false}
          />
          <Marker
            position={[center.lat, center.lng]}
            icon={PIN}
            draggable={editable}
            keyboard={editable}
            title="Plant centre"
            eventHandlers={{
              dragend: (e) => {
                const { lat, lng } = (e.target as L.Marker).getLatLng();
                onPick(lat, lng);
              },
            }}
          />
        </>
      ) : null}
    </MapContainer>
  );
}
