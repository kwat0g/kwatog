import { describe, expect, it } from 'vitest';
import { SHORTCUTS } from './shortcuts';

describe('shortcuts registry', () => {
  it('has unique ids', () => {
    const ids = SHORTCUTS.map((s) => s.id);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('has no duplicate keys per scope', () => {
    const byScope = SHORTCUTS.reduce<Record<string, string[]>>((acc, s) => {
      acc[s.scope] = acc[s.scope] ?? [];
      acc[s.scope].push(s.keys);
      return acc;
    }, {});
    for (const [scope, keys] of Object.entries(byScope)) {
      expect(new Set(keys).size, `scope=${scope} has duplicates: ${keys.join(', ')}`).toBe(keys.length);
    }
  });

  it('every entry has label, hint and group', () => {
    for (const s of SHORTCUTS) {
      expect(s.label, `id=${s.id}`).toBeTruthy();
      expect(s.hint,  `id=${s.id}`).toBeTruthy();
      expect(s.group, `id=${s.id}`).toBeTruthy();
    }
  });

  it('navigation shortcuts have a navigate path', () => {
    const navs = SHORTCUTS.filter((s) => s.group === 'navigation');
    for (const s of navs) {
      expect(s.navigate, `id=${s.id}`).toBeTruthy();
      expect(s.navigate, `id=${s.id}`).toMatch(/^\//);
    }
  });
});
