import { LuArchive, LuList } from '@/lib/icons';
import { SegmentedControl } from './SegmentedControl';
import type { ArchiveScope } from '@/lib/archiveScope';

/**
 * Segmented toggle for filtering tables by archive state. Mirrors the backend
 * `trashed` list parameter: undefined/active = active rows only, `with` =
 * active + archived, `only` = archived rows only.
 *
 * `ArchiveScope` and `archiveToTrashed` live in `@/lib/archiveScope` — this
 * module exports components only, so Fast Refresh keeps working.
 */
export function ArchiveFilter({
 value,
 onChange,
 label = 'Visibility',
 activeLabel = 'Active',
 className,
}: {
 value: ArchiveScope;
 onChange: (scope: ArchiveScope) => void;
 label?: string;
 activeLabel?: string;
 className?: string;
}) {
 return (
  <SegmentedControl<ArchiveScope>
  label={label}
  size="sm"
  className={className}
  value={value}
  onChange={onChange}
  options={[
  { value: 'active', label: activeLabel, ariaLabel: 'Show active records' },
  { value: 'with', label: <span className="inline-flex items-center gap-1"><LuList size={12} />All</span>, ariaLabel: 'Show active and archived records' },
  { value: 'only', label: <span className="inline-flex items-center gap-1"><LuArchive size={12} />Archived</span>, ariaLabel: 'Show archived records only' },
  ]}
  />
 );
}
