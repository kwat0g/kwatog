/**
 * Series F — Task F1. Calendar event types.
 *
 * The backend (CalendarAggregatorService) emits a flat list of these events
 * normalized from heterogeneous source tables (holidays, leaves, deliveries,
 * maintenance, payroll, work orders). The SPA renders them onto a calendar
 * grid; click navigates to `link`.
 */

export type CalendarLayer =
 | 'holiday'
 | 'leave'
 | 'delivery'
 | 'maintenance'
 | 'payroll'
 | 'wo_due';

export type CalendarEventVariant =
 | 'success'
 | 'warning'
 | 'danger'
 | 'info'
 | 'neutral';

export interface CalendarLayerOption {
 value: CalendarLayer;
 label: string;
 variant: CalendarEventVariant;
}

export interface CalendarEvent {
 id: string;
 type: CalendarLayer;
 type_label?: string;
 title: string;
 start: string; // YYYY-MM-DD
 end: string; // YYYY-MM-DD
 all_day: boolean;
 color_variant: CalendarEventVariant;
 link: string;
 meta?: Record<string, unknown>;
}

export interface CalendarEventsResponse {
 data: CalendarEvent[];
 meta: {
 from: string;
 to: string;
 count: number;
 layers: CalendarLayer[];
 };
}

export interface CalendarOptionsResponse {
 data: {
  layers: CalendarLayerOption[];
 };
}

export interface CalendarEventsParams {
 from: string; // YYYY-MM-DD
 to: string;
 layers?: CalendarLayer[];
 department_id?: string; // hash id
}
