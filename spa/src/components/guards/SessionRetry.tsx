import { useEffect, useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';

/** A temporary session-check failure never grants access or implies logout. */
export function SessionRetry() {
  const { bootstrapError, retryAt, bootstrap } = useAuthStore();
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    setNow(Date.now());
    if (retryAt <= Date.now()) return;
    const timer = window.setInterval(() => {
      setNow(Date.now());
      if (Date.now() >= retryAt) window.clearInterval(timer);
    }, 1000);
    return () => window.clearInterval(timer);
  }, [retryAt]);
  const remaining = Math.max(0, Math.ceil((retryAt - now) / 1000));
  return (
    <div role="alert" className="mx-auto max-w-lg space-y-4 p-6 text-primary">
      <h1 className="text-xl font-medium">Session check unavailable</h1>
      <p>{bootstrapError}</p>
      <Button disabled={remaining > 0} onClick={() => void bootstrap()}>
        {remaining > 0 ? `Retry in ${remaining}s` : 'Retry session check'}
      </Button>
    </div>
  );
}
