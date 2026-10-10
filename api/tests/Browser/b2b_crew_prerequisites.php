<?php

declare(strict_types=1);

// Extend the existing disposable B2B master data with actual worker access,
// HR shifts and attendance. No business records or lifecycle states are seeded.
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;

$database = (string) config('database.connections.pgsql.database');
if (! str_starts_with($database, 'ogami_test_o2c_browser_') || $database !== getenv('O2C_BROWSER_DB')) {
    throw new RuntimeException('B2B prerequisites require an explicitly selected disposable database.');
}
$file = getenv('O2C_FIXTURE_PATH');
$fixture = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$start = now()->timezone(config('app.timezone'))->startOfHour()->subHours(2);
$end = $start->copy()->addHours(8);
$shift = Shift::create(['name' => 'B2B acceptance shift '.$fixture['run_id'],
    'start_time' => $start->format('H:i:s'), 'end_time' => $end->format('H:i:s'),
    'break_minutes' => 0, 'is_night_shift' => $end->toDateString() !== $start->toDateString(), 'is_active' => true]);
$department = Department::where('code', 'PROD')->firstOrFail();
$fixture['workers'] = [];
foreach (['employee@ogami.test', 'b2b.worker2@ogami.test'] as $index => $email) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        $employee = Employee::factory()->create(['first_name' => 'B2B', 'last_name' => 'Worker '.($index + 1), 'department_id' => $department->id]);
        $user = User::factory()->create(['name' => $employee->full_name, 'email' => $email, 'employee_id' => $employee->id,
            'role_id' => Role::where('slug', 'employee')->firstOrFail()->id, 'password' => 'password',
            'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }
    app(ShiftAssignmentService::class)->assignToEmployee($user->employee_id, $shift->id, $start->toDateString(), null, [1, 2, 3, 4, 5, 6]);
    Attendance::create(['employee_id' => $user->employee_id, 'date' => $start->toDateString(), 'shift_id' => $shift->id,
        'time_in' => $start, 'status' => 'present', 'is_manual_entry' => true, 'remarks' => 'Disposable B2B acceptance attendance']);
    $fixture['workers'][] = ['id' => $user->employee->hash_id, 'email' => $email, 'name' => $user->employee->full_name];
}
$fixture['shift'] = ['id' => $shift->hash_id, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String()];
$quality = User::where('email', 'qc@ogami.test')->firstOrFail();
$checker = User::factory()->create(['name' => 'B2B Quality Checker', 'email' => 'b2b.qc2@ogami.test',
    'role_id' => $quality->role_id, 'password' => 'password', 'is_active' => true,
    'must_change_password' => false, 'password_changed_at' => now()]);
$fixture['checker_email'] = $checker->email;
file_put_contents($file, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "B2B workers ready.\n";
