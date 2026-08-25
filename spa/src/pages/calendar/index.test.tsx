import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CalendarPage from './index';
import { calendarApi } from '@/api/calendar';

vi.mock('@/api/calendar', () => ({
 calendarApi: {
 options: vi.fn(),
 events: vi.fn(),
 },
}));

const layers = [
 { value: 'holiday', label: 'Holidays', variant: 'info' },
 { value: 'leave', label: 'Leaves', variant: 'neutral' },
 { value: 'delivery', label: 'Deliveries', variant: 'info' },
 { value: 'maintenance', label: 'Maintenance', variant: 'warning' },
 { value: 'payroll', label: 'Payroll', variant: 'success' },
 { value: 'wo_due', label: 'WO due', variant: 'warning' },
] as const;

function todayMonthDate(day: number): string {
 const date = new Date(new Date().getFullYear(), new Date().getMonth(), day);
 const month = String(date.getMonth() + 1).padStart(2, '0');
 return `${date.getFullYear()}-${month}-${String(day).padStart(2, '0')}`;
}

function renderPage(): void {
 const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
 });

 render(
  <QueryClientProvider client={queryClient}>
   <MemoryRouter initialEntries={['/calendar']}>
    <CalendarPage />
   </MemoryRouter>
  </QueryClientProvider>,
 );
}

describe('calendar interaction contract', () => {
 beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(calendarApi.options).mockResolvedValue({ layers: [...layers], departments: [] });
  const eventDate = todayMonthDate(5);
  const events = [
   ['holiday-1', 'Founders Day'],
   ['holiday-2', 'Plant Shutdown'],
   ['holiday-3', 'Safety Briefing'],
   ['holiday-4', 'Overflow Event'],
  ].map(([id, title]) => ({
   id,
   type: 'holiday' as const,
   type_label: 'Holiday',
   title,
   start: eventDate,
   end: eventDate,
   all_day: true,
   color_variant: 'info' as const,
   link: null,
   meta: {},
  }));
  vi.mocked(calendarApi.events).mockResolvedValue({
   data: events,
   meta: {
    from: eventDate,
    to: eventDate,
    count: events.length,
    requested_layers: layers.map((layer) => layer.value),
    layers: layers.map((layer) => layer.value),
    layer_counts: { holiday: { returned: events.length, truncated: false } },
   },
  });
 });

 it('keeps date-only events on their local day and exposes overflow events', async () => {
  renderPage();

  const eventDate = new Date(new Date().getFullYear(), new Date().getMonth(), 5);
  const dateLabel = eventDate.toLocaleDateString('en-US', {
   weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
  });
  const cell = await screen.findByRole('gridcell', { name: new RegExp(dateLabel) });

  expect(cell).toHaveAttribute('aria-label', expect.stringContaining('4 events'));
  const overflow = within(cell).getByRole('button', { name: /Show 1 more event/ });
  fireEvent.click(overflow);

  const dialog = await screen.findByRole('dialog', { name: new RegExp(dateLabel) });
  expect(within(dialog).getByText('Overflow Event')).toBeInTheDocument();
  expect(within(dialog).getAllByRole('button')).toHaveLength(5);
 });

 it('does not request events when the options request fails', async () => {
  vi.mocked(calendarApi.options).mockRejectedValueOnce(new Error('offline'));
  renderPage();

  expect(await screen.findByText('Failed to load calendar options')).toBeInTheDocument();
  expect(calendarApi.events).not.toHaveBeenCalled();
 });
});
