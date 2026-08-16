// Label overrides for the path-derived breadcrumb trail.
//
// DESIGN-SYSTEM.md → "Topbar (48px)" puts breadcrumbs in the topbar, so
// `components/layout/Breadcrumbs` is the app's one trail. It derives crumbs
// from the pathname, which is free and complete but cannot name a record: the
// last segment of /purchasing/purchase-orders/nB4kQ2 is a hash id, and the
// trail used to render it verbatim (or, when the id happened to contain no
// digit, titleize it into "Nb4kqx").
//
// PageHeader already computes the record's human name for `document.title`.
// It registers that string here while mounted, so the trail names the record
// without any page passing a second, competing breadcrumb array.
//
// Deliberately in-memory and single-slot: only one PageHeader is mounted at a
// time, and a stale override outliving its route would mislabel the next one.

import { create } from 'zustand';

interface BreadcrumbState {
  /** Pathname the override applies to. Guards against a stale label. */
  path: string | null;
  /** Human label for the final segment of `path`. */
  label: string | null;
  set: (path: string, label: string) => void;
  /** Clears only if `path` still owns the slot — unmount order is not ordered. */
  clear: (path: string) => void;
}

export const useBreadcrumbStore = create<BreadcrumbState>((set) => ({
  path: null,
  label: null,
  set: (path, label) => set({ path, label }),
  clear: (path) => set((s) => (s.path === path ? { path: null, label: null } : s)),
}));
