import { create } from 'zustand';
import { authApi } from '@/api/auth';

interface SidebarState {
 collapsed: boolean;
 expandAll: boolean;
 mobileOpen: boolean;
 peek: boolean;
 setPeek: (peek: boolean) => void;
 toggle: () => void;
 setCollapsed: (collapsed: boolean) => void;
 setExpandAll: (expandAll: boolean) => void;
 setMobileOpen: (open: boolean) => void;
 init: (initial?: boolean, peek?: boolean, expandAll?: boolean) => void;
}

export const useSidebarStore = create<SidebarState>((set, get) => ({
 collapsed: false,
 expandAll: false,
 mobileOpen: false,
 peek: true,
 setPeek: (peek) => {
 set({ peek });
 void authApi.updatePreferences({ sidebar_peek: peek }).catch(() => { /* Sync on next bootstrap. */ });
 },

 toggle: () => {
 const next = !get().collapsed;
 set({ collapsed: next });

 if (typeof window !== 'undefined') {
 void authApi.updatePreferences({ sidebar_collapsed: next }).catch(() => {
 /* Preference will sync on next bootstrap. */
 });
 }
 },

 setCollapsed: (collapsed) => set({ collapsed }),
 setExpandAll: (expandAll) => {
 set({ expandAll });
 void authApi.updatePreferences({ sidebar_expand_all: expandAll }).catch(() => { /* Sync on next bootstrap. */ });
 },
 setMobileOpen: (open) => set({ mobileOpen: open }),

 init: (initial = false, peek = true, expandAll = false) => {
 set({ collapsed: initial, peek, expandAll, mobileOpen: false });
 // Keep the established narrow-screen rail behavior; module expansion is a separate preference.
 if (typeof window !== 'undefined' && window.innerWidth < 1280) set({ collapsed: true });
 },
}));
