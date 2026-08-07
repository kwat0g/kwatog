import { useCallback, useState } from 'react';
import type { ArchiveScope } from '@/lib/archiveScope';
import { archiveToTrashed } from '@/lib/archiveScope';

/**
 * Manages the archive/trashed filter state for a list page.
 *
 * Returns the raw `trashed` param value the backend understands
 * (`undefined` = active, `'with'` = active + archived, `'only'` = archived)
 * so it can be spread into the list query params. `scope`/`setScope` back the
 * <ArchiveFilter /> toggle.
 */
export function useArchiveFilter(initial: ArchiveScope = 'active') {
 const [scope, setScope] = useState<ArchiveScope>(initial);
 const trashed = archiveToTrashed(scope);
 const toggle = useCallback((next: ArchiveScope) => setScope(next), []);
 return { scope, setScope, toggle, trashed };
}