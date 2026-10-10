<?php

declare(strict_types=1);

use App\Common\Support\ProductionAssertions;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$root = is_dir(__DIR__.'/../api') ? __DIR__.'/../api' : __DIR__.'/..';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
ProductionAssertions::assertDemoSeedingAllowed();
if (! config('app.seed_demo_data') && ! app()->environment('testing')) {
    throw new RuntimeException('Employee test setup requires a demo or dedicated test database.');
}

// This one-time demo setup does not alter normal account provisioning or password policy.
$apply = in_array('--apply', $argv, true);
$options = getopt('', ['apply', 'floor-working-days:', 'office-working-days:', 'actor-email:', 'department:', 'randomize']);
$departmentCode = $options['department'] ?? null;
if ($departmentCode !== null && (! is_string($departmentCode) || ! preg_match('/^[A-Z0-9_-]+$/D', $departmentCode))) {
    throw new InvalidArgumentException('Department must be an exact department code.');
}
$randomize = array_key_exists('randomize', $options);
$workingDays = [];
foreach (['floor', 'office'] as $group) {
    $value = $options[$group.'-working-days'] ?? null;
    if ($value === null) {
        continue;
    }
    if (! is_string($value) || ! preg_match('/^[1-7](?:,[1-7])*$/D', $value)) {
        throw new InvalidArgumentException('Working days must be comma-separated ISO weekdays from 1 to 7.');
    }
    $days = array_map('intval', explode(',', $value));
    if (count($days) !== count(array_unique($days))) {
        throw new InvalidArgumentException('Working days must not contain duplicate weekdays.');
    }
    sort($days);
    $workingDays[$group] = $days;
}
if ($workingDays !== [] && count($workingDays) !== 2) {
    throw new InvalidArgumentException('Provide both floor and office working days.');
}
if ($apply && $workingDays !== []) {
    $actor = User::where('email', $options['actor-email'] ?? '')->where('is_active', true)->firstOrFail();
    abort_unless($actor->hasPermission('attendance.shifts.manage'), 403);
    auth()->setUser($actor);
    request()->attributes->set('source_command', 'employee-test-setup');
}
$effective = now()->startOfDay()->subDay();
$floorDepartments = ['PROD', 'WH', 'QC', 'MAINT', 'MOLD'];
$shiftDefinitions = [
    ['name' => 'Day Shift', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_night_shift' => false],
    ['name' => 'Afternoon Shift', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'is_night_shift' => false],
    ['name' => 'Night Shift (8 hours)', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_night_shift' => true],
    ['name' => 'Office Hours', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'is_night_shift' => false],
];
$report = DB::transaction(function () use ($apply, $effective, $floorDepartments, $shiftDefinitions, $workingDays, $departmentCode, $randomize) {
    $employees = Employee::query()->where('status', 'active')->with('department', 'user.role')
        ->when($departmentCode !== null, fn ($query) => $query->whereHas('department', fn ($department) => $department->where('code', $departmentCode)))
        ->orderBy('id')->lockForUpdate()->get();
    if ($departmentCode !== null && $employees->isEmpty()) {
        throw new RuntimeException('No active employees found in the selected department.');
    }
    if ($randomize) {
        $employees = $employees->shuffle();
    }
    $shifts = [];
    foreach ($shiftDefinitions as $definition) {
        $shift = Shift::query()->where('is_active', true)->where('start_time', $definition['start_time'])
            ->where('end_time', $definition['end_time'])->first();
        if (! $shift && $apply) {
            $shift = Shift::create($definition + ['break_minutes' => $definition['name'] === 'Office Hours' ? 60 : 30,
                'is_active' => true, 'is_default' => false]);
        }
        $shifts[] = $shift;
    }
    $role = Role::where('slug', 'employee')->firstOrFail();
    $provisioning = app(UserProvisioningService::class);
    $shiftService = app(ShiftAssignmentService::class);
    $counts = ['employees' => $employees->count(), 'accounts_created' => 0, 'accounts_preserved' => 0,
        'shifts_assigned' => 0, 'shifts_preserved' => 0, 'email_collisions_resolved' => 0,
        'calendars_configured' => 0, 'calendars_preserved' => 0];
    $byDepartment = [];
    foreach ($employees as $employee) {
        $department = $employee->department?->code;
        $current = $shiftService->current($employee, now());
        if (! in_array($department, $floorDepartments, true) || ! $current?->is_active) {
            continue;
        }
        foreach (array_slice($shiftDefinitions, 0, 3) as $index => $definition) {
            if ($current->start_time === $definition['start_time'] && $current->end_time === $definition['end_time']) {
                $byDepartment[$department] ??= [0, 0, 0];
                $byDepartment[$department][$index]++;
                break;
            }
        }
    }
    $rows = [];
    $calendarChanges = [];
    $emails = User::withTrashed()->pluck('email')->mapWithKeys(fn ($email) => [strtolower($email) => true])->all();
    foreach ($employees as $employee) {
        $user = $employee->user ?? User::withTrashed()->where('employee_id', $employee->id)->first();
        if (! $user) {
            $email = strtolower(trim((string) $employee->email));
            if ($email !== '' && isset($emails[$email])) {
                $base = 'employee-'.$employee->id;
                $email = $base.'@ogami.test';
                for ($suffix = 1; isset($emails[$email]); $suffix++) {
                    $email = $base.'-'.$suffix.'@ogami.test';
                }
                $counts['email_collisions_resolved']++;
            } else {
                $email = $provisioning->resolveEmailFor($employee);
                if (isset($emails[$email])) {
                    $base = 'employee-'.$employee->id;
                    $email = $base.'@ogami.test';
                    for ($suffix = 1; isset($emails[$email]); $suffix++) {
                        $email = $base.'-'.$suffix.'@ogami.test';
                    }
                }
            }
            $emails[$email] = true;
            if ($apply) {
                $user = User::create(['name' => $employee->full_name, 'email' => $email,
                    'password' => Hash::make('password'), 'role_id' => $role->id, 'employee_id' => $employee->id,
                    'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now(),
                    'failed_login_attempts' => 0]);
                $user->setRelation('role', $role);
                PasswordHistory::create(['user_id' => $user->id, 'password_hash' => $user->password, 'created_at' => now()]);
            }
            $counts['accounts_created']++;
            $accountStatus = 'created';
        } else {
            $email = $user->email;
            $counts['accounts_preserved']++;
            $accountStatus = $user->trashed() ? 'preserved (deleted)' : 'preserved';
        }

        $department = $employee->department?->code ?? 'Unassigned';
        $shift = $shiftService->current($employee, now());
        if ($shift?->is_active) {
            $shiftName = $shift->name;
            $counts['shifts_preserved']++;
        } else {
            $index = 3;
            if (in_array($department, $floorDepartments, true)) {
                $departmentCounts = $byDepartment[$department] ?? [0, 0, 0];
                $index = array_search(min($departmentCounts), $departmentCounts, true);
                $departmentCounts[$index]++;
                $byDepartment[$department] = $departmentCounts;
            }
            $future = EmployeeShiftAssignment::where('employee_id', $employee->id)
                ->where('effective_date', '>', now()->toDateString())->orderBy('effective_date')->first();
            if ($apply) {
                $shiftService->assignToEmployee($employee->id, $shifts[$index]->id, $effective->toDateString(),
                    $future?->effective_date->copy()->subDay()->toDateString(),
                    $workingDays[in_array($department, $floorDepartments, true) ? 'floor' : 'office'] ?? null);
            }
            $shiftName = $shifts[$index]?->name ?? $shiftDefinitions[$index]['name'];
            $counts['shifts_assigned']++;
        }
        if ($workingDays !== []) {
            $days = $workingDays[in_array($department, $floorDepartments, true) ? 'floor' : 'office'];
            $assignments = EmployeeShiftAssignment::where('employee_id', $employee->id)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($assignments as $assignment) {
                if ($assignment->working_days !== null) {
                    $counts['calendars_preserved']++;

                    continue;
                }
                $calendarChanges[] = ['assignment_id' => $assignment->id, 'employee_no' => $employee->employee_no,
                    'shift_id' => $assignment->shift_id, 'effective_date' => $assignment->effective_date->toDateString(),
                    'end_date' => $assignment->end_date?->toDateString(), 'before' => null, 'after' => $days];
                if ($apply) {
                    $shiftService->configureWorkingDays($assignment, $days);
                }
                $counts['calendars_configured']++;
            }
        }
        $rows[] = ['employee_no' => $employee->employee_no, 'name' => $employee->full_name,
            'department' => $department, 'email' => $email, 'role' => $user?->role?->slug ?? 'employee',
            'account' => $accountStatus, 'shift' => $shiftName];
    }

    return ['applied' => $apply, 'database' => DB::connection()->getDatabaseName(), 'department' => $departmentCode,
        'effective_date' => $effective->toDateString(), 'working_days' => $workingDays,
        'counts' => $counts, 'calendar_changes' => $calendarChanges, 'accounts' => $rows];
});
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
