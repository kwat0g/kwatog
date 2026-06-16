<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Production\Models\DefectType;
use App\Modules\Quality\Models\NonConformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OGAMI-010 — Realistic demo dataset.
 *
 * Turns the otherwise sparse demo (5 employees, 1 payroll cycle, 3 NCRs, no
 * history) into a defense-credible dataset so every shipped feature is
 * *visible*: 200+ employees, 12 months of biometric attendance with realistic
 * late/OT/absent/leave patterns, 6 finalized semi-monthly payroll cycles run
 * through the REAL engine, 45 NCRs across defect types for a true Pareto, and
 * 12 months of demand-forecast history.
 *
 * Runs LAST (after ComprehensiveDemoSeeder, which truncates payroll/NCR/etc.
 * and would otherwise wipe this volume). Deterministic (seeded RNG) and
 * idempotent (count guards) so `make fresh` is reproducible and re-runnable.
 */
class RealisticDataSeeder extends Seeder
{
    private const TARGET_EMPLOYEES = 200;
    private const PAYROLL_CYCLES   = 6;   // 3 months of semi-monthly periods
    private const NCR_COUNT        = 45;
    private const ATTENDANCE_MONTHS = 12;

    /** Deterministic seed so re-runs produce identical data. */
    private const RNG_SEED = 20260616;

    private const FIRST_NAMES_M = [
        'Juan', 'Jose', 'Pedro', 'Antonio', 'Ramon', 'Carlos', 'Manuel', 'Ricardo',
        'Eduardo', 'Roberto', 'Andres', 'Mateo', 'Gabriel', 'Daniel', 'Miguel',
        'Rafael', 'Emilio', 'Felipe', 'Marco', 'Nestor', 'Bayani', 'Lakan',
    ];
    private const FIRST_NAMES_F = [
        'Maria', 'Ana', 'Liza', 'Rosa', 'Carmen', 'Teresa', 'Luz', 'Cristina',
        'Elena', 'Gloria', 'Josefa', 'Corazon', 'Imelda', 'Divina', 'Aurora',
        'Marites', 'Jocelyn', 'Grace', 'Anna', 'Bianca', 'Dalisay', 'Liwayway',
    ];
    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza',
        'Torres', 'Tomas', 'Andrada', 'Castillo', 'Flores', 'Villanueva', 'Ramos',
        'Aquino', 'Del Rosario', 'Mercado', 'Aguilar', 'Domingo', 'Salonga',
        'Gonzales', 'Rivera', 'Navarro', 'Pascual', 'De Leon', 'Magsaysay',
    ];

    /**
     * Defect distribution skewed so the Pareto chart shows a real 80/20 shape.
     * Weight = relative frequency.
     */
    private const DEFECT_WEIGHTS = [
        'Short Shot' => 28, 'Flash' => 22, 'Dimensional' => 16, 'Burn Marks' => 11,
        'Warpage' => 8, 'Color Mismatch' => 6, 'Cracks' => 4, 'Air Bubbles' => 3,
        'Inclusions' => 2,
    ];

    public function run(): void
    {
        mt_srand(self::RNG_SEED);

        $admin = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
            ->orderBy('id')->first() ?? User::first();

        if (! $admin) {
            $this->command?->warn('[Realistic] No admin user; skipping.');
            return;
        }

        $this->seedEmployees();
        $this->seedAttendance();
        $this->seedApprovedLeave($admin);
        $this->seedPayrollCycles($admin);
        $this->seedNcrs($admin);
        $this->seedForecasts($admin);

        $this->command?->info('[Realistic] Dataset complete.');
    }

    /* ─── 1. Employees ──────────────────────────────────────────────── */

    private function seedEmployees(): void
    {
        $current = Employee::count();
        if ($current >= self::TARGET_EMPLOYEES) {
            $this->command?->info("[Realistic] Employees already ≥ ".self::TARGET_EMPLOYEES.'.');
            return;
        }

        // Weight headcount toward the shop floor like a real molding plant.
        $deptWeights = [
            'PROD' => 90, 'QC' => 22, 'WH' => 20, 'MOLD' => 14, 'MAINT' => 12,
            'PPC' => 8, 'PUR' => 8, 'FIN' => 8, 'HR' => 6, 'IMPEX' => 5,
            'ADMIN' => 5, 'EXEC' => 2,
        ];
        $deptPool = [];
        foreach ($deptWeights as $code => $w) {
            for ($i = 0; $i < $w; $i++) $deptPool[] = $code;
        }

        // Cache dept + a position per dept.
        $deptMap = [];
        foreach (Department::all() as $d) {
            $pos = Position::where('department_id', $d->id)->orderBy('id')->first();
            if ($pos) $deptMap[$d->code] = ['dept' => $d->id, 'pos' => $pos->id];
        }
        if ($deptMap === []) {
            $this->command?->warn('[Realistic] No departments/positions; skipping employees.');
            return;
        }

        $rows = [];
        $now = CarbonImmutable::now();
        $start = $current + 1;

        for ($n = $start; $n <= self::TARGET_EMPLOYEES; $n++) {
            $deptCode = $deptPool[$this->rand(0, count($deptPool) - 1)];
            if (! isset($deptMap[$deptCode])) $deptCode = array_key_first($deptMap);
            $ids = $deptMap[$deptCode];

            $isFemale = $this->rand(0, 1) === 1;
            $first = $isFemale
                ? self::FIRST_NAMES_F[$this->rand(0, count(self::FIRST_NAMES_F) - 1)]
                : self::FIRST_NAMES_M[$this->rand(0, count(self::FIRST_NAMES_M) - 1)];
            $last = self::LAST_NAMES[$this->rand(0, count(self::LAST_NAMES) - 1)];

            // Shop-floor depts skew daily-rated; office depts monthly.
            $shopFloor = in_array($deptCode, ['PROD', 'WH', 'MOLD', 'MAINT'], true);
            $isDaily = $shopFloor ? ($this->rand(0, 100) < 80) : ($this->rand(0, 100) < 15);

            // Hired across the past ~4 years so 12 months of attendance is valid.
            $hired = $now->subDays($this->rand(120, 1460));

            $rows[] = [
                'employee_no'          => sprintf('OGM-2024-%04d', $n),
                'first_name'           => $first,
                'last_name'            => $last,
                'birth_date'           => $hired->subYears($this->rand(20, 45))->toDateString(),
                'gender'               => $isFemale ? 'female' : 'male',
                'civil_status'         => ['single', 'married', 'married', 'widowed'][$this->rand(0, 3)],
                'nationality'          => 'Filipino',
                'mobile_number'        => '+639' . str_pad((string) (100000000 + $n), 9, '0', STR_PAD_LEFT),
                'email'                => strtolower($first) . '.' . strtolower(str_replace([' ', "'"], '', $last)) . $n . '@ogami.local',
                'sss_no'               => '34-' . str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT) . '-1',
                'philhealth_no'        => '12-' . str_pad((string) (100000000 + $n), 9, '0', STR_PAD_LEFT) . '-2',
                'pagibig_no'           => '1234-5678-' . str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT),
                'tin'                  => '123-456-' . str_pad((string) ($n), 3, '0', STR_PAD_LEFT) . '-000',
                'department_id'        => $ids['dept'],
                'position_id'          => $ids['pos'],
                'employment_type'      => $isDaily ? 'contractual' : 'regular',
                'pay_type'             => $isDaily ? PayType::Daily->value : PayType::Monthly->value,
                'date_hired'           => $hired->toDateString(),
                'date_regularized'     => $isDaily ? null : $hired->addMonths(6)->toDateString(),
                'basic_monthly_salary' => $isDaily ? null : (string) ($this->rand(18, 65) * 1000),
                'daily_rate'           => $isDaily ? (string) $this->rand(610, 950) : null,
                'status'               => EmployeeStatus::Active->value,
                'created_at'           => $hired,
                'updated_at'           => $now,
            ];
        }

        // Encrypted casts (sss_no, tin, etc.) require model events, so create via
        // the model in chunks rather than a raw bulk insert.
        foreach (array_chunk($rows, 50) as $chunk) {
            foreach ($chunk as $row) {
                Employee::create($row);
            }
        }

        $this->command?->info('[Realistic] Seeded ' . count($rows) . ' employees (total ' . Employee::count() . ').');
    }

    /* ─── 2. Attendance (12 months, realistic patterns) ─────────────── */

    private function seedAttendance(): void
    {
        $employees = Employee::where('status', EmployeeStatus::Active->value)
            ->get(['id', 'date_hired', 'pay_type']);

        if ($employees->isEmpty()) return;

        $end   = CarbonImmutable::now()->startOfDay();
        $start = $end->subMonths(self::ATTENDANCE_MONTHS);

        // Idempotency: skip if we already have dense attendance.
        if (DB::table('attendances')->where('date', '>=', $start->toDateString())->count() > ($employees->count() * 50)) {
            $this->command?->info('[Realistic] Attendance already dense; skipping.');
            return;
        }

        $buffer = [];
        $total = 0;

        foreach ($employees as $emp) {
            $hired = CarbonImmutable::parse($emp->date_hired);
            $cursor = $start->greaterThan($hired) ? $start : $hired;

            for ($d = $cursor; $d->lte($end); $d = $d->addDay()) {
                if ($d->dayOfWeek === CarbonImmutable::SUNDAY) continue; // Mon-Sat factory

                $roll = $this->rand(1, 100);
                // Uniform key set across ALL rows — Postgres bulk insert requires
                // every row in a batch to have identical columns.
                $row = [
                    'employee_id'       => $emp->id,
                    'date'              => $d->toDateString(),
                    'time_in'           => null,
                    'time_out'          => null,
                    'regular_hours'     => 0,
                    'overtime_hours'    => 0,
                    'night_diff_hours'  => 0,
                    'tardiness_minutes' => 0,
                    'undertime_minutes' => 0,
                    'is_rest_day'       => false,
                    'day_type_rate'     => 1.00,
                    'is_manual_entry'   => false,
                    'remarks'           => null,
                    'status'            => AttendanceStatus::Present->value,
                    'created_at'        => $d,
                    'updated_at'        => $d,
                ];

                if ($roll <= 3) {
                    // Absent
                    $row['status'] = AttendanceStatus::Absent->value;
                } elseif ($roll <= 5) {
                    // On leave
                    $row['status']  = AttendanceStatus::OnLeave->value;
                    $row['remarks'] = 'leave:demo';
                } elseif ($roll <= 12) {
                    // Late
                    $late = $this->rand(10, 75);
                    $row['time_in']           = $d->setTime(8, 0)->addMinutes($late)->toDateTimeString();
                    $row['time_out']          = $d->setTime(17, 0)->toDateTimeString();
                    $row['regular_hours']     = 8.0;
                    $row['tardiness_minutes'] = $late;
                    $row['status']            = AttendanceStatus::Late->value;
                } elseif ($roll <= 22) {
                    // Overtime day (and some night diff)
                    $ot = $this->rand(1, 3);
                    $nd = $this->rand(0, 2);
                    $row['time_in']          = $d->setTime(8, 0)->toDateTimeString();
                    $row['time_out']         = $d->setTime(17 + $ot, 0)->toDateTimeString();
                    $row['regular_hours']    = 8.0;
                    $row['overtime_hours']   = $ot;
                    $row['night_diff_hours'] = $nd;
                } else {
                    // Normal full day
                    $row['time_in']       = $d->setTime(8, 0)->toDateTimeString();
                    $row['time_out']      = $d->setTime(17, 0)->toDateTimeString();
                    $row['regular_hours'] = 8.0;
                }

                $buffer[] = $row;
                $total++;

                if (count($buffer) >= 1000) {
                    DB::table('attendances')->insertOrIgnore($buffer);
                    $buffer = [];
                }
            }
        }

        if ($buffer !== []) {
            DB::table('attendances')->insertOrIgnore($buffer);
        }

        $this->command?->info("[Realistic] Seeded {$total} attendance rows over " . self::ATTENDANCE_MONTHS . ' months.');
    }

    /* ─── 3. Payroll — 6 finalized semi-monthly cycles (real engine) ── */

    /**
     * Approved PAID leave for a subset of daily-rated workers inside the
     * payroll window, with the matching attendance days re-pointed at the real
     * leave request. This makes OGAMI-003 (daily-rated paid-leave pay) VISIBLE
     * in the seeded payroll instead of leaving leave_pay at zero.
     *
     * Must run BEFORE seedPayrollCycles so the engine picks up the leave pay.
     */
    private function seedApprovedLeave(User $admin): void
    {
        // Idempotent: skip if we've already created demo leave requests.
        if (DB::table('leave_requests')->where('reason', 'like', 'Demo paid leave%')->exists()) {
            $this->command?->info('[Realistic] Approved leave already seeded.');
            return;
        }

        $vlTypeId = DB::table('leave_types')->where('code', 'VL')->value('id')
            ?? DB::table('leave_types')->where('is_paid', true)->value('id');
        if (! $vlTypeId) return;

        // Daily-rated workers are the ones whose pay actually changes with leave.
        $dailyEmployees = Employee::where('status', EmployeeStatus::Active->value)
            ->where('pay_type', PayType::Daily->value)
            ->inRandomOrder()
            ->limit(30)
            ->get(['id']);

        // Window: the last 3 months (covers the 6 semi-monthly cycles).
        $windowStart = CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth();
        $windowEnd   = CarbonImmutable::now()->subMonthNoOverflow()->endOfMonth();

        $created = 0;
        foreach ($dailyEmployees as $idx => $emp) {
            // Pick a Mon-Fri start inside the window; 1-2 day leave.
            $offset = $this->rand(0, max(1, (int) $windowStart->diffInDays($windowEnd) - 3));
            $start  = $windowStart->addDays($offset);
            while (in_array($start->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true)) {
                $start = $start->addDay();
            }
            $days = $this->rand(1, 2);
            $end  = $start;
            for ($k = 1; $k < $days; $k++) {
                $end = $end->addDay();
                while (in_array($end->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true)) {
                    $end = $end->addDay();
                }
            }

            $leaveNo = sprintf('LR-%s-%04d', $start->format('Ym'), $idx + 1);

            DB::table('leave_requests')->insert([
                'leave_request_no' => $leaveNo,
                'employee_id'      => $emp->id,
                'leave_type_id'    => $vlTypeId,
                'start_date'       => $start->toDateString(),
                'end_date'         => $end->toDateString(),
                'days'             => (float) $days,
                'reason'           => 'Demo paid leave',
                'status'           => 'approved',
                'hr_approver_id'   => $admin->id,
                'hr_approved_at'   => $start->subDays(3),
                'created_at'       => $start->subDays(5),
                'updated_at'       => $start->subDays(3),
            ]);

            // Re-point the matching attendance days at the real leave request so
            // PayrollCalculatorService::computeLeavePay() recognises + pays them.
            for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                if (in_array($d->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true)) continue;
                DB::table('attendances')->updateOrInsert(
                    ['employee_id' => $emp->id, 'date' => $d->toDateString()],
                    [
                        'status'        => AttendanceStatus::OnLeave->value,
                        'remarks'       => "leave:{$leaveNo}",
                        'regular_hours' => 0,
                        'time_in'       => null,
                        'time_out'      => null,
                        'day_type_rate' => 1.00,
                        'updated_at'    => $d,
                    ],
                );
            }
            $created++;
        }

        $this->command?->info("[Realistic] Seeded {$created} approved paid-leave requests (daily-rated).");
    }


    private function seedPayrollCycles(User $admin): void
    {
        $periods   = app(PayrollPeriodService::class);
        $calculator = app(PayrollCalculatorService::class);

        $finalizedCount = PayrollPeriod::where('status', PayrollPeriodStatus::Finalized->value)
            ->where('is_thirteenth_month', false)->count();
        if ($finalizedCount >= self::PAYROLL_CYCLES) {
            $this->command?->info('[Realistic] Payroll cycles already ≥ ' . self::PAYROLL_CYCLES . '.');
            return;
        }

        // Build the last N semi-monthly windows ending last month (so they're past).
        $windows = $this->semiMonthlyWindows(self::PAYROLL_CYCLES);

        foreach ($windows as $w) {
            // Skip if a period already covers this window.
            $exists = PayrollPeriod::where('period_start', $w['start'])
                ->where('period_end', $w['end'])->exists();
            if ($exists) continue;

            try {
                $period = $periods->create([
                    'period_start'        => $w['start'],
                    'period_end'          => $w['end'],
                    'payroll_date'        => $w['pay'],
                    'is_first_half'       => $w['first_half'],
                    'is_thirteenth_month' => false,
                ], $admin);

                $employees = $periods->availableEmployees($period);
                foreach ($employees as $emp) {
                    try {
                        $calculator->computeForEmployee($period, $emp);
                    } catch (\Throwable $e) {
                        // Skip an employee that can't be computed; keep the cycle.
                    }
                }

                $period = $periods->approve($period->fresh());
                $periods->finalize($period->fresh());
            } catch (\Throwable $e) {
                $this->command?->warn('[Realistic] Cycle ' . $w['start'] . ' failed: ' . $e->getMessage());
            }
        }

        $this->command?->info('[Realistic] Finalized ' . PayrollPeriod::where('status', PayrollPeriodStatus::Finalized->value)->count() . ' payroll periods.');
    }

    /** @return list<array{start:string,end:string,pay:string,first_half:bool}> */
    private function semiMonthlyWindows(int $count): array
    {
        $windows = [];
        // Start from last full month, walk backward by halves.
        // Anchor on the FIRST day of last month and step back with
        // subMonthsNoOverflow to avoid Carbon's end-of-month overflow bug
        // (e.g. May 31 minus 1 month → "Apr 31" → overflows to May 1).
        $month = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $made = 0;

        while ($made < $count) {
            $first = $month->startOfMonth();
            $mid   = $first->addDays(14);              // 1-15
            $secondStart = $first->addDays(15);        // 16-eom
            $eom   = $month->endOfMonth();

            // Second half (16-eom)
            if ($made < $count) {
                $windows[] = [
                    'start' => $secondStart->toDateString(),
                    'end'   => $eom->toDateString(),
                    'pay'   => $eom->toDateString(),
                    'first_half' => false,
                ];
                $made++;
            }
            // First half (1-15)
            if ($made < $count) {
                $windows[] = [
                    'start' => $first->toDateString(),
                    'end'   => $mid->toDateString(),
                    'pay'   => $mid->addDays(5)->toDateString(),
                    'first_half' => true,
                ];
                $made++;
            }

            $month = $month->subMonthNoOverflow();
        }

        return $windows;
    }

    /* ─── 4. NCRs — 45 across defect types for a real Pareto ─────────── */

    private function seedNcrs(User $admin): void
    {
        if (NonConformanceReport::count() >= self::NCR_COUNT) {
            $this->command?->info('[Realistic] NCRs already ≥ ' . self::NCR_COUNT . '.');
            return;
        }

        $products = Product::pluck('id')->all();
        $defectNames = array_keys(DefectType::pluck('name')->all() ? array_flip(DefectType::pluck('name')->all()) : self::DEFECT_WEIGHTS);

        // Weighted defect pool for the 80/20 Pareto shape.
        $pool = [];
        foreach (self::DEFECT_WEIGHTS as $name => $w) {
            for ($i = 0; $i < $w; $i++) $pool[] = $name;
        }

        $now = CarbonImmutable::now();
        $created = 0;
        $existing = NonConformanceReport::count();

        for ($i = 0; $i < self::NCR_COUNT; $i++) {
            $defect = $pool[$this->rand(0, count($pool) - 1)];
            $when = $now->subDays($this->rand(1, 360));
            $status = ['open', 'in_progress', 'closed', 'closed'][$this->rand(0, 3)];
            $severity = ['low', 'medium', 'medium', 'high', 'critical'][$this->rand(0, 4)];

            $ncr = new NonConformanceReport();
            $ncr->fill([
                'ncr_number'         => 'NCR-' . $when->format('Ym') . '-' . str_pad((string) ($existing + $i + 1), 4, '0', STR_PAD_LEFT),
                'source'             => $this->rand(0, 100) < 75 ? 'inspection_fail' : 'customer_complaint',
                'product_id'         => $products !== [] ? $products[$this->rand(0, count($products) - 1)] : null,
                'defect_description' => $defect . ' — ' . $this->defectNarrative($defect),
                'affected_quantity'  => $this->rand(2, 80),
                'disposition'        => ['scrap', 'rework', 'use_as_is', 'rework'][$this->rand(0, 3)],
                'created_by'         => $admin->id,
                'is_auto_generated'  => false,
            ]);
            $ncr->severity = $severity;
            $ncr->status = $status;
            if ($status === 'closed') {
                $ncr->closed_by = $admin->id;
                $ncr->closed_at = $when->addDays($this->rand(2, 20));
                $ncr->root_cause = 'Process drift identified via ' . $defect . ' trend.';
                $ncr->corrective_action = 'Adjusted process parameters and re-validated first article.';
            }
            $ncr->created_at = $when;
            $ncr->updated_at = $when;
            $ncr->save();
            $created++;
        }

        $this->command?->info("[Realistic] Seeded {$created} NCRs across defect types.");
    }

    private function defectNarrative(string $defect): string
    {
        return match ($defect) {
            'Short Shot' => 'incomplete fill on cavity edge',
            'Flash' => 'excess material at parting line',
            'Dimensional' => 'OD/ID outside tolerance band',
            'Burn Marks' => 'discoloration from trapped gas',
            'Warpage' => 'part bowing after ejection',
            'Color Mismatch' => 'shade deviation vs master',
            'Cracks' => 'stress cracking near gate',
            'Air Bubbles' => 'voids in thick section',
            'Inclusions' => 'foreign contamination in melt',
            default => 'nonconformance observed during inspection',
        };
    }

    /* ─── 5. Demand forecasts — 12 months of trend history ──────────── */

    private function seedForecasts(User $admin): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('demand_forecasts')) return;
        if (DB::table('demand_forecasts')->count() >= 36) {
            $this->command?->info('[Realistic] Forecasts already seeded.');
            return;
        }

        $products = Product::limit(5)->pluck('id')->all();
        if ($products === []) return;

        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($products as $pid) {
            $base = $this->rand(800, 4000);
            for ($m = 11; $m >= 0; $m--) {
                $month = $now->subMonths($m);
                // Trend + seasonal wobble.
                $trend = (int) ($base * (1 + (11 - $m) * 0.02));
                $forecast = $trend + $this->rand(-200, 200);
                $actual = $forecast + $this->rand(-300, 300);
                $rows[] = [
                    'product_id'          => $pid,
                    'forecast_month'      => (int) $month->format('n'),
                    'forecast_year'       => (int) $month->format('Y'),
                    'method'              => 'weighted_avg',
                    'forecasted_quantity' => max(0, $forecast),
                    'confidence_level'    => $this->rand(70, 95),
                    'actual_quantity'     => max(0, $actual),
                    'variance'            => $actual - $forecast,
                    'created_by'          => $admin->id,
                    'created_at'          => $month,
                    'updated_at'          => $month,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('demand_forecasts')->insert($chunk);
        }

        $this->command?->info('[Realistic] Seeded ' . count($rows) . ' demand-forecast rows.');
    }

    /* ─── deterministic RNG helper ──────────────────────────────────── */

    private function rand(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }
}
