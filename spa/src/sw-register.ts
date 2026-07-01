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

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js').catch(() => {
        // Service worker registration failed — app still works without it
      });
    });
  }
}
