import { useEffect, useState } from 'react';

interface SavedFeedbackOptions {
  duration?: number;
  onExpire?: () => void;
}

export function useSavedFeedback({ duration = 1600, onExpire }: SavedFeedbackOptions = {}) {
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (!saved) return;
    const timeout = window.setTimeout(() => {
      setSaved(false);
      onExpire?.();
    }, duration);
    return () => window.clearTimeout(timeout);
  }, [duration, onExpire, saved]);

  return { saved, markSaved: () => setSaved(true) };
}
