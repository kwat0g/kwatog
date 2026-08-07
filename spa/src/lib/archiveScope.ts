/**
 * Archive-scope vocabulary shared by the `ArchiveFilter` control and every list
 * page that reads the backend `trashed` list parameter.
 *
 * Lives outside `ui/ArchiveFilter.tsx` so that file exports components only —
 * mixing helpers into a component module defeats React Fast Refresh, which is
 * what `react-refresh/only-export-components` flags.
 */

/** UI-side archive filter state. */
export type ArchiveScope = 'active' | 'with' | 'only';

/**
 * Map the UI scope onto the API's `trashed` parameter. `active` is the API
 * default and is sent as `undefined` so it drops out of the query string
 * entirely rather than appearing as an explicit no-op filter.
 */
export function archiveToTrashed(scope: ArchiveScope): 'with' | 'only' | undefined {
  if (scope === 'active') return undefined;
  return scope;
}
