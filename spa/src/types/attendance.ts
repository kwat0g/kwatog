import type { Department } from './hr';

export interface Shift {
 id: string;
 name: string;
 start_time: string;
 end_time: string;
 break_minutes: number;
 is_night_shift: boolean;
 is_extended: boolean;
 auto_ot_hours: string | null;
 is_active: boolean;
 is_default: boolean;
 created_at: string;
 updated_at: string;
}

export interface CreateShiftData {
 name: string;
 start_time: string;
 end_time: string;
 break_minutes?: number;
 is_night_shift?: boolean;
 is_extended?: boolean;
 auto_ot_hours?: number | null;
 is_active?: boolean;
 is_default?: boolean;
}

export type UpdateShiftData = Partial<CreateShiftData>;

export interface BulkAssignShiftData {
 department_id: string;
 shift_id: string;
 effective_date: string;
 end_date?: string | null;
}

// ─── Holidays (Task 17) ────────────────────────────────────────
export interface Holiday {
 id: string;
 name: string;
 date: string;
 type: 'regular' | 'special_non_working';
 type_label?: string;
 is_recurring: boolean;
 created_at: string;
 updated_at: string;
}

export interface CreateHolidayData {
 name: string;
 date: string;
 type: 'regular' | 'special_non_working';
 is_recurring?: boolean;
}

export type UpdateHolidayData = Partial<CreateHolidayData>;

// ─── Attendance (Task 18) ──────────────────────────────────────
export type AttendanceStatus = 'present' | 'absent' | 'late' | 'halfday' | 'on_leave' | 'holiday' | 'rest_day';

export interface Attendance {
 id: string;
 employee: { id: string; full_name: string; employee_no: string } | null;
 date: string;
 shift: Shift | null;
 time_in: string | null;
 time_out: string | null;
 regular_hours: string;
 overtime_hours: string;
 night_diff_hours: string;
 tardiness_minutes: number;
 undertime_minutes: number;
 holiday_type: string | null;
 is_rest_day: boolean;
 day_type_rate: string;
 status: AttendanceStatus;
 status_label?: string;
 is_manual_entry: boolean;
 remarks: string | null;
}

export interface OvertimeRequest {
 id: string;
 employee: { id: string; full_name: string; employee_no: string } | null;
 date: string;
 hours_requested: string;
 reason: string;
 status: 'pending' | 'approved' | 'rejected';
 status_label?: string;
 approver: { id: string; name: string } | null;
 approved_at: string | null;
 rejection_reason: string | null;
 is_auto_detected: boolean;
 created_at: string;
 updated_at: string;
}

export interface OvertimeOptions {
 /** Minimum approved OT minutes, in hours (e.g. 0.5). */
 minimum_hours: number;
 /** Admin/HR create form daily ceiling (settings attendance.ot.admin_max_hours). */
 maximum_hours: number;
 /** Self-service request minimum. */
 request_min_hours: number;
 /** How many days ahead OT may be requested. */
 request_future_days: number;
 /** How many days back OT may be requested. */
 request_past_days: number;
 /** Ordinary-day OT premium multiplier (e.g. 1.25). */
 premium_multiplier: number;
}

export type { Department };
