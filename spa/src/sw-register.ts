// Each touch PWA installs as its own home-screen app: distinct manifest, title
// and icon so "Add to Home Screen" lands the operator in the right sub-app.
const PWA_MANIFESTS: Array<{
  prefix: string;
  manifest: string;
  title: string;
  icon: string;
}> = [
  {
    prefix: '/driver',
    manifest: '/driver-manifest.webmanifest',
    title: 'OGAMI Driver',
    icon: '/driver-icon-192.png',
  },
  {
    prefix: '/factory',
    manifest: '/factory-manifest.webmanifest',
    title: 'Ogami Shop Floor',
    icon: '/ogami-icon-192.png',
  },
];

export function registerSW() {
  // Swap the manifest link based on which PWA sub-app we're in.
  const manifestLink = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
  const app = PWA_MANIFESTS.find((m) => window.location.pathname.startsWith(m.prefix));
  if (manifestLink && app) {
    manifestLink.href = app.manifest;
    const titleMeta = document.querySelector<HTMLMetaElement>(
      'meta[name="apple-mobile-web-app-title"]',
    );
    if (titleMeta) titleMeta.content = app.title;
    const touchIcon = document.querySelector<HTMLLinkElement>('link[rel="apple-touch-icon"]');
    if (touchIcon) touchIcon.href = app.icon;
  }

  if (!('serviceWorker' in navigator)) return;

  // A service worker must never control Vite development. The worker used to
  // cache /src/* and /@react-refresh responses, which could combine an old
  // transformed module with a newer refresh runtime after an upgrade/HMR
  // cycle. Remove any worker and Ogami cache left by an earlier dev session.
  if (import.meta.env.DEV) {
    void navigator.serviceWorker
      .getRegistrations()
      .then((registrations) =>
        Promise.all(registrations.map((registration) => registration.unregister())),
      );

    if ('caches' in window) {
      void caches
        .keys()
        .then((keys) =>
          Promise.all(
            keys.filter((key) => key.startsWith('ogami-')).map((key) => caches.delete(key)),
          ),
        );
    }
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Service worker registration failed — app still works without it
    });
  });
}
