import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuClock } from '@/lib/icons';
import { punchApi } from '@/api/attendance/punch';
import { useFeature } from '@/hooks/useFeature';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';
import type { GpsFix } from '@/types/attendance';

const GEO_ERRORS: Record<number, string> = {
  1: 'Allow location access for this site in your browser settings, then try again.',
  2: 'Your location could not be determined. Move to an open area and try again.',
  3: 'Locating you took too long. Try again.',
};

function readFix(): Promise<GpsFix> {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new Error('This device cannot provide a location.'));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      (e) => reject(new Error(GEO_ERRORS[e.code] ?? GEO_ERRORS[2])),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  });
}

function serverMessage(error: unknown): string {
  const msg = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
  return msg ?? (error instanceof Error ? error.message : 'Could not record your punch.');
}

export function TimeClockCard() {
  const enabled = useFeature('attendance');
  const qc = useQueryClient();
  const [notice, setNotice] = useState<string | null>(null);
  const [locating, setLocating] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance', 'punch', 'status'],
    queryFn: punchApi.status,
    enabled,
  });

  const punch = useMutation({
    mutationFn: async () => punchApi.punch(await readFix()),
    onMutate: () => {
      setNotice(null);
      setLocating(true);
    },
    onSuccess: (r) => {
      toast.success(r.direction === 'in' ? 'Timed in' : 'Timed out');
      qc.invalidateQueries({ queryKey: ['attendance'] });
      qc.invalidateQueries({ queryKey: ['self-service'] });
    },
    onError: (e) => {
      const message = serverMessage(e);
      setNotice(message);
      toast.error(message);
    },
    onSettled: () => setLocating(false),
  });

  if (!enabled) return null;

  const next = data?.next_action ?? 'in';
  const label = punch.isPending ? (locating ? 'Locating…' : 'Punching…') : next === 'in' ? 'Time In' : 'Time Out';

  return (
    <Panel title="Time In / Out">
      {isLoading && <SkeletonBlock className="h-16" />}
      {isError && <p className="text-sm text-danger">Could not load your time clock. Refresh to retry.</p>}
      {data && (
        <div className="flex flex-col gap-3">
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted">
              {data.shift ? `Shift ${data.shift.start}–${data.shift.end}` : 'No shift assigned'}
            </span>
            {data.tardiness_minutes ? <Chip variant="warning">Late by {data.tardiness_minutes} min</Chip> : null}
          </div>
          <Button
            variant="primary"
            size="lg"
            icon={<LuClock size={16} />}
            disabled={punch.isPending}
            onClick={() => punch.mutate()}
          >
            {label}
          </Button>
          {notice && <p role="alert" className="text-sm text-danger">{notice}</p>}
          {data.punches.length > 0 && (
            <ul className="text-xs font-mono tabular-nums text-muted">
              {data.punches.map((p) => (
                <li key={p.punched_at}>
                  {p.direction === 'in' ? 'In' : 'Out'} {formatTime(p.punched_at)}
                </li>
              ))}
            </ul>
          )}
          <p className="text-2xs text-muted">
            Your location is read only at the moment you punch, and only works within {data.radius_m} m of the plant.
          </p>
        </div>
      )}
    </Panel>
  );
}
