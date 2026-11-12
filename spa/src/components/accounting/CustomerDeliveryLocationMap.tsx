import { useEffect } from 'react';
import L from 'leaflet';
import { MapContainer, Marker, TileLayer, ZoomControl, useMap, useMapEvents } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import '@/components/admin/plant-location-map.css';

interface Props {
  center: { lat: number; lng: number } | null;
  onPick: (latitude: number, longitude: number) => void;
}

const OSM_TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const FALLBACK_CENTER: [number, number] = [14.286, 120.9345];
const PIN = L.divIcon({
  className: 'plant-location-pin',
  html: '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5" opacity="0.55"/><circle cx="12" cy="12" r="4.2"/><line x1="12" y1="1.5" x2="12" y2="7.6"/><line x1="12" y1="16.4" x2="12" y2="22.5"/><line x1="1.5" y1="12" x2="7.6" y2="12"/><line x1="16.4" y1="12" x2="22.5" y2="12"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/></svg>',
  iconSize: [44, 44],
  iconAnchor: [22, 22],
});

function PinPlacement({ onPick }: Pick<Props, 'onPick'>) {
  useMapEvents({ click: (event) => onPick(event.latlng.lat, event.latlng.lng) });
  return null;
}

function MapView({ center }: Pick<Props, 'center'>) {
  const map = useMap();
  const latitude = center?.lat;
  const longitude = center?.lng;
  useEffect(() => {
    const frame = requestAnimationFrame(() => map.invalidateSize());
    return () => cancelAnimationFrame(frame);
  }, [map]);
  useEffect(() => {
    if (latitude !== undefined && longitude !== undefined) {
      map.setView([latitude, longitude], Math.max(map.getZoom(), 15), { animate: false });
    }
  }, [latitude, longitude, map]);
  return null;
}

export default function CustomerDeliveryLocationMap({ center, onPick }: Props) {
  return (
    <MapContainer
      className="plant-location-map is-editable h-full w-full"
      center={center ? [center.lat, center.lng] : FALLBACK_CENTER}
      zoom={15}
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
      <PinPlacement onPick={onPick} />
      <MapView center={center} />
      {center && (
        <Marker
          position={[center.lat, center.lng]}
          icon={PIN}
          draggable
          keyboard
          title="Delivery point"
          eventHandlers={{ dragend: (event) => {
            const point = (event.target as L.Marker).getLatLng();
            onPick(point.lat, point.lng);
          } }}
        />
      )}
    </MapContainer>
  );
}
