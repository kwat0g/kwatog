import { create } from 'zustand';
import { authApi } from '@/api/auth';

interface SidebarState {
 collapsed: boolean;
 mobileOpen: boolean;
 peek: boolean;
 setPeek: (peek: boolean) => void;
 toggle: () => void;
 setCollapsed: (collapsed: boolean) => void;
 setMobileOpen: (open: boolean) => void;
 init: (initial?: boolean, peek?: boolean) => void;
}

export const useSidebarStore = create<SidebarState>((set, get) => ({
 collapsed: false,
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
 setMobileOpen: (open) => set({ mobileOpen: open }),

 init: (initial = false, peek = true) => {
 set({ collapsed: initial, peek, mobileOpen: false });
 // Auto-collapse on narrow viewports.
 if (typeof window !== 'undefined' && window.innerWidth < 1280) {
 set({ collapsed: true });
 }
 },
}));
