import { useEffect } from 'react';
import { toast } from 'react-hot-toast';
import { Button } from '@/components/ui/Button';

const VERSION_TOAST_ID = 'ogami-deploy-version-update';
const VERSION_CHECK_INTERVAL_MS = 60_000;

function isVersionManifest(value: unknown): value is { version: string } {
  return (
    typeof value === 'object' &&
    value !== null &&
    'version' in value &&
    typeof value.version === 'string' &&
    value.version.length > 0
  );
}

/** Prompts an active production SPA to reload after a new build is deployed. */
export function DeployVersionPrompt() {
  useEffect(() => {
    if (import.meta.env.DEV) return;

    let stopped = false;
    let checking = false;
    let promptedVersion: string | null = null;

    const checkVersion = async () => {
      if (stopped || checking || document.visibilityState !== 'visible') return;
      checking = true;

      try {
        const response = await fetch('/version.json', {
          cache: 'no-store',
          credentials: 'omit',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;

        const manifest: unknown = await response.json();
        if (
          stopped ||
          !isVersionManifest(manifest) ||
          manifest.version === __OGAMI_BUILD_ID__ ||
          promptedVersion === manifest.version
        ) {
          return;
        }

        promptedVersion = manifest.version;
        toast.custom(
          (t) => (
            <div
              className={`flex max-w-[min(32rem,calc(100vw-2rem))] items-center gap-3 rounded-md border border-default bg-elevated px-4 py-3 shadow-menu transition-opacity duration-fast ${
                t.visible ? 'opacity-100' : 'opacity-0'
              }`}
              role="status"
              aria-live="polite"
              aria-atomic="true"
            >
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-primary">A new version is ready</p>
                <p className="mt-0.5 text-xs text-secondary">
                  Reload to get the latest updates.
                </p>
              </div>
              <Button size="sm" onClick={() => window.location.reload()}>
                Reload
              </Button>
              <Button size="sm" variant="ghost" onClick={() => toast.dismiss(t.id)}>
                Later
              </Button>
            </div>
          ),
          { id: VERSION_TOAST_ID, duration: Infinity, position: 'top-center' },
        );
      } catch {
        // A failed version check must not interfere with normal application use.
      } finally {
        checking = false;
      }
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') void checkVersion();
    };
    const intervalId = window.setInterval(() => void checkVersion(), VERSION_CHECK_INTERVAL_MS);

    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', handleVisibilityChange);
    void checkVersion();

    return () => {
      stopped = true;
      window.clearInterval(intervalId);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      window.removeEventListener('focus', handleVisibilityChange);
      toast.dismiss(VERSION_TOAST_ID);
    };
  }, []);

  return null;
}
