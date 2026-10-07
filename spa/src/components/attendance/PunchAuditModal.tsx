import { useQuery } from '@tanstack/react-query';
import { LuExternalLink } from '@/lib/icons';
import { punchApi } from '@/api/attendance/punch';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Modal } from '@/components/ui/Modal';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';

interface Props {
  attendanceId: string | null;
  onClose: () => void;
}

const TH = 'px-3 py-2 text-xs font-medium text-muted border-b border-default whitespace-nowrap';
const TD = 'px-3 h-9 border-b border-subtle whitespace-nowrap';

function mapUrl(lat: string, lng: string): string {
  return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=18/${lat}/${lng}`;
}

export function PunchAuditModal({ attendanceId, onClose }: Props) {
  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ['attendance', 'punch-audit', attendanceId],
    queryFn: () => punchApi.audit(attendanceId as string),
    enabled: attendanceId !== null,
  });

  return (
    <Modal isOpen={attendanceId !== null} onClose={onClose} title="Time in/out punches" size="lg">
      <div className="flex flex-col gap-3">
        {isLoading && (
          <div className="flex flex-col gap-2" aria-busy="true" aria-label="Loading punches">
            <SkeletonBlock className="h-9 w-full" />
            <SkeletonBlock className="h-9 w-full" />
            <SkeletonBlock className="h-9 w-full" />
          </div>
        )}

        {isError && (
          <div className="flex flex-col items-start gap-3">
            <p className="text-sm text-secondary">Could not load the punches for this day.</p>
            <Button variant="secondary" size="sm" loading={isFetching} onClick={() => refetch()}>
              Try again
            </Button>
          </div>
        )}

        {data && data.length === 0 && (
          <p className="text-sm text-muted">
            No app punches for this day. The times on this record came from the biometric import or
            a manual entry.
          </p>
        )}

        {data && data.length > 0 && (
          <>
            <div className="overflow-x-auto rounded-md border border-default">
              <table className="w-full text-sm">
                <thead className="bg-surface">
                  <tr className="text-left">
                    <th scope="col" className={TH}>
                      Type
                    </th>
                    <th scope="col" className={TH}>
                      Time
                    </th>
                    <th scope="col" className={`${TH} text-right`}>
                      Distance
                    </th>
                    <th scope="col" className={`${TH} text-right`}>
                      GPS ±
                    </th>
                    <th scope="col" className={TH}>
                      Location
                    </th>
                    <th scope="col" className={TH}>
                      IP
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {data.map((p) => (
                    <tr key={p.id} className="last:[&>td]:border-b-0">
                      <td className={TD}>
                        <Chip variant={p.direction === 'in' ? 'success' : 'info'}>
                          {p.direction === 'in' ? 'In' : 'Out'}
                        </Chip>
                      </td>
                      <td className={`${TD} font-mono tabular-nums`}>{formatTime(p.punched_at)}</td>
                      <td className={`${TD} text-right font-mono tabular-nums`}>
                        {p.distance_m} m
                      </td>
                      <td className={`${TD} text-right font-mono tabular-nums`}>
                        {p.accuracy_m} m
                      </td>
                      <td className={`${TD} font-mono tabular-nums`}>
                        {p.latitude && p.longitude ? (
                          <span className="inline-flex items-center gap-2">
                            <span>{`${p.latitude}, ${p.longitude}`}</span>
                            <a
                              href={mapUrl(p.latitude, p.longitude)}
                              target="_blank"
                              rel="noopener noreferrer"
                              aria-label="Open this location in OpenStreetMap (opens a new tab)"
                              className="text-accent hover:text-accent-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-sm"
                            >
                              <LuExternalLink size={13} aria-hidden />
                            </a>
                          </span>
                        ) : (
                          <span className="text-muted">—</span>
                        )}
                      </td>
                      <td className={`${TD} font-mono tabular-nums`}>
                        {p.ip ?? <span className="text-muted">—</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="text-xs text-muted">
              Exact coordinates are kept for 90 days, then removed. Distance and GPS accuracy are
              kept.
            </p>
          </>
        )}
      </div>
    </Modal>
  );
}
