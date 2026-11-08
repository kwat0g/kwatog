export const maintenanceScheduleQueryKeys = {
  lists: ['maintenance', 'schedules', 'list'] as const,
  list: (scope: string, filters: unknown) => ['maintenance', 'schedules', 'list', scope, filters] as const,
  details: ['maintenance', 'schedules', 'detail'] as const,
  detail: (id: string) => ['maintenance', 'schedules', 'detail', id] as const,
  windowSuggestions: (id: string) => ['maintenance', 'schedules', 'window-suggestions', id] as const,
  options: ['maintenance', 'schedules', 'options'] as const,
};
