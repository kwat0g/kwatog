// Records top-level page visits into the recent-items store so the ⌘K
// palette's "Recent" group reflects where the user actually works.
// Only exact nav-item matches are recorded — detail/record URLs are added
// by the palette itself when a result is picked.

import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { SECTIONS, isNavItemVisible } from '@/components/layout/Sidebar';
import { useAuthStore } from '@/stores/authStore';
import { useRecentItemsStore } from '@/stores/recentItemsStore';

export function useRecentPageTracker() {
  const { pathname } = useLocation();
  const permissions = useAuthStore((s) => s.permissions);
  const features = useAuthStore((s) => s.features);
  const roleSlug = useAuthStore((s) => s.user?.role?.slug);
  const addRecent = useRecentItemsStore((s) => s.add);

  useEffect(() => {
    for (const section of SECTIONS) {
      const match = section.items.find(
        (item) =>
          item.to === pathname &&
          isNavItemVisible(item, { permissions, features, roleSlug }),
      );
      if (match) {
        addRecent({ url: match.to, label: match.label, sublabel: section.label, type: 'page' });
        return;
      }
    }
  }, [pathname, permissions, features, roleSlug, addRecent]);
}
