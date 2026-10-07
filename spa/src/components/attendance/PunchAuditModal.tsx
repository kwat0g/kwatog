import { useQuery } from '@tanstack/react-query';
import { punchApi } from '@/api/attendance/punch';
import { Modal } from '@/components/ui/Modal';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';

interface Props {
  attendanceId: string | null;
  onClose: () => void;
}

export function PunchAuditModal({ attendanceId, onClose }: Props) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance', 'punch-audit', attendanceId],
    queryFn: () => punchApi.audit(attendanceId as string),
    enabled: attendanceId !== null,
  });

  return (
    <Modal isOpen={attendanceId !== null} onClose={onClose} title="Time in/out punches">
      {isLoading && <SkeletonBlock className="h-24" />}
      {isError && <p className="text-sm text-danger">Could not load punches.</p>}
      {data && data.length === 0 && <p className="text-sm text-muted">No app punches for this day.</p>}
      {data && data.length > 0 && (
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-muted">
              <th>Type</th>
              <th>Time</th>
              <th>Distance</th>
              <th>GPS ±</th>
              <th>Location</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody className="font-mono tabular-nums">
            {data.map((p) => (
              <tr key={p.id}>
                <td>
                  <Chip variant={p.direction === 'in' ? 'success' : 'info'}>
                    {p.direction === 'in' ? 'In' : 'Out'}
                  </Chip>
                </td>
                <td>{formatTime(p.punched_at)}</td>
                <td>{p.distance_m} m</td>
                <td>{p.accuracy_m} m</td>
                <td>{p.latitude && p.longitude ? `${p.latitude}, ${p.longitude}` : '—'}</td>
                <td>{p.ip ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Modal>
  );
}
