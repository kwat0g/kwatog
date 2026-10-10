import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { GanttChart } from './GanttChart';
import type { GanttRow } from '@/types/mrp';

const rows: GanttRow[] = [
  {
    machine_id: 'machine-1',
    machine_code: 'IMM-01',
    name: 'Injection machine',
    tonnage: 100,
    status: 'idle',
    bars: [0, 1, 2].map((index) => ({
      id: `schedule-${index}`,
      wo_id: `wo-${index}`,
      wo_number: `WO-${index}`,
      product_name: 'Wiper bushing',
      mold_code: `MOLD-${index}`,
      status: 'pending',
      wo_status: 'planned',
      start: `2026-10-11T0${index}:00:00+08:00`,
      end: `2026-10-11T0${index + 1}:00:00+08:00`,
    })),
  },
];

describe('GanttChart', () => {
  it('keeps every same-day work order in a separate visible timeline row', () => {
    const onBarClick = vi.fn();
    render(<GanttChart rows={rows} onBarClick={onBarClick} />);
    const bars = rows[0].bars.map((bar) =>
      screen.getByRole('button', { name: new RegExp(bar.wo_number!) }),
    );
    expect(new Set(bars.map((bar) => bar.parentElement)).size).toBe(3);
    fireEvent.click(bars[0]);
    expect(onBarClick).toHaveBeenCalledWith('wo-0');
  });

  it('positions Manila midnight under Oct 11 rather than the UTC date Oct 10', () => {
    render(<GanttChart rows={rows} />);
    expect(screen.getByRole('button', { name: /WO-0/ })).toHaveAttribute(
      'data-start-date',
      '2026-10-11',
    );
  });
});
