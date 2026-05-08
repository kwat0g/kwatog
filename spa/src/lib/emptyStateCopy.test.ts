import { describe, expect, it } from 'vitest';
import { EMPTY_STATE_COPY, emptyStateCopyFor } from './emptyStateCopy';

describe('emptyStateCopy', () => {
  it('returns the registered copy for a known route', () => {
    const copy = emptyStateCopyFor('/hr/employees');
    expect(copy.icon).toBe('users');
    expect(copy.title).toMatch(/no employees yet/i);
  });

  it('falls back to a generic empty copy for unknown routes', () => {
    const copy = emptyStateCopyFor('/this/route/does/not/exist');
    expect(copy.icon).toBe('inbox');
    expect(copy.title).toBeTruthy();
  });

  it('every entry has a non-empty title and description', () => {
    for (const [route, copy] of Object.entries(EMPTY_STATE_COPY)) {
      expect(copy.title, `route=${route} title`).toBeTruthy();
      expect(copy.description, `route=${route} description`).toBeTruthy();
      expect(copy.icon, `route=${route} icon`).toBeTruthy();
      expect(copy.itemNoun, `route=${route} itemNoun`).toBeTruthy();
    }
  });

  it('action route, when set, is an absolute path', () => {
    for (const [route, copy] of Object.entries(EMPTY_STATE_COPY)) {
      if (copy.actionRoute) {
        expect(copy.actionRoute, `route=${route} actionRoute`).toMatch(/^\//);
      }
    }
  });
});
