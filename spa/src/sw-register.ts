export function registerSW() {
 // Swap the manifest link based on which PWA sub-app we're in
 const manifestLink = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
 if (manifestLink && window.location.pathname.startsWith('/driver')) {
 manifestLink.href = '/driver-manifest.webmanifest';
 const titleMeta = document.querySelector<HTMLMetaElement>('meta[name="apple-mobile-web-app-title"]');
 if (titleMeta) titleMeta.content = 'OGAMI Driver';
 const touchIcon = document.querySelector<HTMLLinkElement>('link[rel="apple-touch-icon"]');
 if (touchIcon) touchIcon.href = '/driver-icon-192.png';
 }

 if (!('serviceWorker' in navigator)) return;

 // A service worker must never control Vite development. The worker used to
 // cache /src/* and /@react-refresh responses, which could combine an old
 // transformed module with a newer refresh runtime after an upgrade/HMR
 // cycle. Remove any worker and Ogami cache left by an earlier dev session.
 if (import.meta.env.DEV) {
 void navigator.serviceWorker.getRegistrations().then((registrations) =>
 Promise.all(registrations.map((registration) => registration.unregister())),
 );

 if ('caches' in window) {
 void caches.keys().then((keys) =>
 Promise.all(
 keys
 .filter((key) => key.startsWith('ogami-'))
 .map((key) => caches.delete(key)),
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
