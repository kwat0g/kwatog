import { client } from './client';
import type {
 CalendarEventsParams,
 CalendarEventsResponse,
 CalendarOptionsResponse,
} from '@/types/calendar';

/**
 * Series F — Task F1. Calendar API client.
 */
export const calendarApi = {
 options: () => client.get<CalendarOptionsResponse>('/calendar/options').then((r) => r.data.data),
 events: (params: CalendarEventsParams) =>
 client
 .get<CalendarEventsResponse>('/calendar/events', {
 params: {
 from: params.from,
 to: params.to,
 'layers[]': params.layers,
 department_id: params.department_id,
 },
 })
 .then((r) => r.data),
};
