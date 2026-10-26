import { expect, it } from 'vitest';
import { formatDateTime } from './formatDate';

it('formats offset timestamps in Manila time regardless of browser timezone', () => {
 expect(formatDateTime('2026-10-01T09:14:00+08:00')).toBe('Oct 1, 2026 · 9:14 AM');
 expect(formatDateTime('2026-10-01T01:14:00Z')).toBe('Oct 1, 2026 · 9:14 AM');
});
