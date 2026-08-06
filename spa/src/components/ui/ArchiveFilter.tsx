import { Archive, ArchiveRestore, List } from 'lucide-react';
import { SegmentedControl } from './SegmentedControl';

export type ArchiveScope = 'active' | 'with' | 'only';

/**
 * Segmented toggle for filtering tables by archive state. Mirrors the backend
 * `trashed` list parameter: undefined/active = active rows only, `with` =
 * active + archived, `only` = archived rows only.
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
  { value: 'with', label: <span className="inline-flex items-center gap-1"><List size={12} />All</span>, ariaLabel: 'Show active and archived records' },
  { value: 'only', label: <span className="inline-flex items-center gap-1"><Archive size={12} />Archived</span>, ariaLabel: 'Show archived records only' },
  ]}
  />
 );
}

export function archiveToTrashed(scope: ArchiveScope): 'with' | 'only' | undefined {
 if (scope === 'active') return undefined;
 return scope;
}

export function trashedToArchive(trashed: string | undefined): ArchiveScope {
 if (trashed === 'with') return 'with';
 if (trashed === 'only') return 'only';
 return 'active';
}

export const RestoreIcon = ArchiveRestore;
