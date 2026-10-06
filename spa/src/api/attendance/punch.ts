import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { AttendancePunchAudit, GpsFix, PunchResult, PunchStatus } from '@/types/attendance';

export const punchApi = {
  status: () => client.get<ApiSuccess<PunchStatus>>('/attendance/punch/status').then((r) => r.data.data),
  punch: (fix: GpsFix) => client.post<ApiSuccess<PunchResult>>('/attendance/punch', fix).then((r) => r.data.data),
  audit: (attendanceId: string) =>
    client.get<{ data: AttendancePunchAudit[] }>(`/attendance/attendances/${attendanceId}/punches`).then((r) => r.data.data),
};
