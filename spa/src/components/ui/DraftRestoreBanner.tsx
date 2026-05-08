// Series X / Task X2 — restore-draft notification banner.
//
// Render at the top of a form when `useFormDraftAutosave` reports
// `hasDraft === true`. Sensitive fields are NEVER restored — they were
// excluded at write-time by `stripSensitive`.

import { Clock } from 'lucide-react';

interface Props {
  ageMs: number | null;
  onRestore: () => void;
  onDiscard: () => void;
}

function formatAge(ms: number): string {
  const sec = Math.floor(ms / 1000);
  if (sec < 60) return 'just now';
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min} minute${min === 1 ? '' : 's'} ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr} hour${hr === 1 ? '' : 's'} ago`;
  const day = Math.floor(hr / 24);
  return `${day} day${day === 1 ? '' : 's'} ago`;
}

export function DraftRestoreBanner({ ageMs, onRestore, onDiscard }: Props) {
  const ageLabel = ageMs !== null ? formatAge(ageMs) : '';
  return (
    <div
      role="status"
      className="flex items-center justify-between gap-3 mx-5 mt-4 px-3 py-2 rounded-md border border-warning bg-warning-bg text-warning-fg"
    >
      <div className="flex items-center gap-2">
        <Clock size={14} />
        <p className="text-sm">
          You have an unsaved draft from <span className="font-medium">{ageLabel}</span>. Sensitive fields are
          not restored.
        </p>
      </div>
      <div className="flex items-center gap-1.5">
        <button
          type="button"
          onClick={onRestore}
          className="h-7 px-2.5 rounded-md text-xs font-medium bg-warning text-canvas hover:opacity-90"
        >
          Restore
        </button>
        <button
          type="button"
          onClick={onDiscard}
          className="h-7 px-2.5 rounded-md text-xs text-warning-fg hover:bg-warning/20"
        >
          Discard
        </button>
      </div>
    </div>
  );
}
