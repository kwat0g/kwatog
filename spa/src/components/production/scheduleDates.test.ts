import { expect, it } from 'vitest';
import {
  productionDateTime,
  productionInputDateTime,
  productionInputIso,
  scheduleTime,
} from './scheduleDates';
import { formatTime } from '@/lib/formatDate';

it('shows 12-hour plant time, including midnight and noon', () => {
  expect(scheduleTime('2026-10-06T16:00:00Z', 'Asia/Manila')).toBe('12:00 AM');
  expect(scheduleTime('2026-10-07T04:00:00Z', 'Asia/Manila')).toBe('12:00 PM');
  expect(productionDateTime('2026-10-06T20:38:00Z')).toBe('Oct 7, 2026 · 4:38 AM');
  expect(productionInputDateTime('2026-10-06T20:38:00Z')).toBe('2026-10-07T04:38');
  expect(productionInputIso(productionInputDateTime('2026-10-06T20:38:00Z'))).toBe(
    '2026-10-06T20:38:00.000Z',
  );
});

it('formats shift clock values without treating them as calendar dates', () => {
  expect(formatTime('00:00:00')).toBe('12:00 AM');
  expect(formatTime('14:00')).toBe('2:00 PM');
  expect(formatTime('22:00:00')).toBe('10:00 PM');
  expect(formatTime(null)).toBe('—');
  expect(formatTime('invalid')).toBe('—');
});
