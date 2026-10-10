import { lazy, useSyncExternalStore } from 'react';

const DesktopDetail = lazy(() => import('./detail'));
const TouchDetail = lazy(() => import('@/pages/maintenance/mobile/work-order'));
const query = '(min-width: 768px)';

function subscribe(notify: () => void) {
  const media = window.matchMedia(query);
  media.addEventListener('change', notify);
  return () => media.removeEventListener('change', notify);
}

function isDesktop() {
  return window.matchMedia(query).matches;
}

export default function ResponsiveMaintenanceWorkOrderDetail() {
  const desktop = useSyncExternalStore(subscribe, isDesktop, () => false);
  // shortcut: crossing the breakpoint resets unsaved dialog fields; lift draft state if live resizing matters.
  return desktop ? <DesktopDetail /> : <TouchDetail />;
}
