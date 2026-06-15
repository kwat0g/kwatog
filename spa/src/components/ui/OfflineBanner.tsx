import { useEffect, useState } from 'react';
import { WifiOff } from 'lucide-react';

/**
 * Sticky banner shown when the browser detects it is offline.
 * Dismisses automatically when connectivity returns.
 */
export function OfflineBanner() {
  const [offline, setOffline] = useState(false);

  useEffect(() => {
    const onOnline = () => setOffline(false);
    const onOffline = () => setOffline(true);
    setOffline(!navigator.onLine);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    return () => {
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  if (!offline) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      className="sticky top-12 z-30 px-4 py-1.5 bg-warning-bg border-b border-warning text-warning-fg text-xs flex items-center justify-center gap-2"
    >
      <WifiOff size={12} />
      <span className="font-medium">You are offline.</span>
      <span className="opacity-80">Some features may be unavailable until your connection returns.</span>
    </div>
  );
}
