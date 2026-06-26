# Recruitment Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a recruitment module to the Ogami ERP so external applicants can apply via a public `/careers` page and HR manages a 5-stage pipeline, with applicant tracking and hire-to-employee conversion.

**Architecture:** Recruitment lives inside the existing HR module (`api/app/Modules/HR/`) as a sub-namespace. Public-facing endpoints use the existing Landing module pattern (no auth, rate-limited). SPA has public `/careers/*` routes and authenticated `/hr/recruitment/*` routes. Mail for applicant confirmations.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16, React 18, TypeScript, TanStack Query, Zod, Axios, Tailwind CSS

## Global Constraints

- All models use `HasHashId` trait; API resources return `hash_id`, never raw integer `id`
- Money fields: `decimal(15,2)` — never float
- Financial ops wrapped in `DB::transaction()`
- Migrations numbered sequentially from `0248_`
- Test seeds: column varchars mostly 20 chars, use `'XX-T-'.substr(uniqid(), -5)` (10 chars)
- User+role seed in tests: `User::factory()->create(['role_id' => Role::query()->where('slug', X)->value('id')])`
- `Storage::fake('local')` for file upload tests (NOT `'public'`)
- Frontend: never set `Content-Type` header on FormData requests — let browser auto-set
- Module routes auto-mount via `ModuleServiceProvider` — HR module already loaded
- Public routes follow Landing module pattern: no `auth:sanctum`, use `throttle:` middleware

---

### Task 1: Database Migrations + Enums

**Files:**
- Create: `api/database/migrations/0248_create_job_postings_table.php`
- Create: `api/database/migrations/0249_create_job_applications_table.php`
- Create: `api/database/migrations/0250_create_application_interviews_table.php`
- Create: `api/database/migrations/0251_create_application_notes_table.php`
- Create: `api/app/Modules/HR/Enums/JobPostingStatus.php`
- Create: `api/app/Modules/HR/Enums/ApplicationStage.php`
- Create: `api/app/Modules/HR/Enums/InterviewOutcome.php`

**Interfaces:**
- Consumes: Existing `positions`, `departments`, `employees`, `users` tables
- Produces: 4 tables (`job_postings`, `job_applications`, `application_interviews`, `application_notes`) + 3 enums used by all subsequent tasks

- [ ] **Step 1: Create `JobPostingStatus` enum**

```php
// api/app/Modules/HR/Enums/JobPostingStatus.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum JobPostingStatus: string
{
    case Draft  = 'draft';
    case Open   = 'open';
    case Closed = 'closed';
    case Filled = 'filled';

    public function label(): string
    {
        return match ($this) {
            self::Draft  => 'Draft',
            self::Open   => 'Open',
            self::Closed => 'Closed',
            self::Filled => 'Filled',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
```

- [ ] **Step 2: Create `ApplicationStage` enum**

```php
// api/app/Modules/HR/Enums/ApplicationStage.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ApplicationStage: string
{
    case New       = 'new';
    case Screening = 'screening';
    case Interview = 'interview';
    case Offer     = 'offer';
    case Hired     = 'hired';
    case Rejected  = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New       => 'New',
            self::Screening => 'Screening',
            self::Interview => 'Interview',
            self::Offer     => 'Offer',
            self::Hired     => 'Hired',
            self::Rejected  => 'Rejected',
        };
    }

    /** Friendly label for public tracking page (no internal jargon). */
    public function publicLabel(): string
    {
        return match ($this) {
            self::New, self::Screening => 'Application Received',
            self::Interview            => 'Under Review',
            self::Offer                => 'Offer Extended',
            self::Hired                => 'Hired',
            self::Rejected             => 'Not Selected',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Hired, self::Rejected], true);
    }

    /** Return the next stage in the forward-only pipeline. null = already terminal. */
    public function next(): ?self
    {
        return match ($this) {
            self::New       => self::Screening,
            self::Screening => self::Interview,
            self::Interview => self::Offer,
            self::Offer     => self::Hired,
            default         => null,
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
```

- [ ] **Step 3: Create `InterviewOutcome` enum**

```php
// api/app/Modules/HR/Enums/InterviewOutcome.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum InterviewOutcome: string
{
    case Pending = 'pending';
    case Passed  = 'passed';
    case Failed  = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Passed  => 'Passed',
            self::Failed  => 'Failed',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
```

- [ ] **Step 4: Create `0248_create_job_postings_table` migration**

```php
// api/database/migrations/0248_create_job_postings_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $t) {
            $t->id();
            $t->string('posting_number', 20)->unique();
            $t->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $t->foreignId('department_id')->constrained('departments');
            $t->string('title', 200);
            $t->text('description');
            $t->text('requirements');
            $t->string('employment_type', 30);
            $t->decimal('salary_range_min', 15, 2)->nullable();
            $t->decimal('salary_range_max', 15, 2)->nullable();
            $t->boolean('show_salary')->default(false);
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('slots')->default(1);
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'posted_at']);
            $t->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
```

- [ ] **Step 5: Create `0249_create_job_applications_table` migration**

```php
// api/database/migrations/0249_create_job_applications_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $t) {
            $t->id();
            $t->string('application_number', 20)->unique();
            $t->foreignId('job_posting_id')->constrained('job_postings');
            $t->string('tracking_code', 10)->unique();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('email', 255);
            $t->string('phone', 30);
            $t->string('resume_path', 500);
            $t->string('resume_original_name', 255);
            $t->text('cover_letter')->nullable();
            $t->string('stage', 20)->default('new');
            $t->string('rejected_at_stage', 20)->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreignId('converted_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->timestamp('applied_at')->useCurrent();
            $t->timestamps();

            $t->index(['job_posting_id', 'stage']);
            $t->index('email');
            $t->index('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
```

- [ ] **Step 6: Create `0250_create_application_interviews_table` migration**

```php
// api/database/migrations/0250_create_application_interviews_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_interviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_application_id')->constrained('job_applications')->cascadeOnDelete();
            $t->timestamp('scheduled_at');
            $t->string('location', 200)->nullable();
            $t->string('interviewer_name', 200);
            $t->text('notes')->nullable();
            $t->string('outcome', 20)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_interviews');
    }
};
```

- [ ] **Step 7: Create `0251_create_application_notes_table` migration**

```php
// api/database/migrations/0251_create_application_notes_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_application_id')->constrained('job_applications')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_notes');
    }
};
```

- [ ] **Step 8: Run migrations and verify**

Run: `cd api && php artisan migrate`

Expected: 4 tables created, no errors.

- [ ] **Step 9: Commit**

```bash
git add api/database/migrations/024{8,9}*.php api/database/migrations/025{0,1}*.php api/app/Modules/HR/Enums/JobPostingStatus.php api/app/Modules/HR/Enums/ApplicationStage.php api/app/Modules/HR/Enums/InterviewOutcome.php
git commit -m "feat(recruitment): add migrations and enums for recruitment module"
```

---

### Task 2: Models + Document Sequences + Permissions Seeder

**Files:**
- Create: `api/app/Modules/HR/Models/JobPosting.php`
- Create: `api/app/Modules/HR/Models/JobApplication.php`
- Create: `api/app/Modules/HR/Models/ApplicationInterview.php`
- Create: `api/app/Modules/HR/Models/ApplicationNote.php`
- Modify: `api/app/Common/Services/DocumentSequenceService.php` (add `job_posting` and `job_application` entries to CONFIG)
- Modify: `api/database/seeders/RolePermissionSeeder.php` (add `hr_recruitment` permission group + assign to `hr_officer`)
- Modify: `api/database/seeders/SettingsSeeder.php` (add `recruitment` feature toggle)

**Interfaces:**
- Consumes: Tables from Task 1, enums from Task 1, existing `HasHashId`, `HasAuditLog`, `SoftDeletes` traits
- Produces: 4 Eloquent models, 2 document sequence types (`job_posting`, `job_application`), 4 permissions (`hr.recruitment.view`, `hr.recruitment.manage`, `hr.recruitment.applications`, `hr.recruitment.hire`), `recruitment` feature toggle

- [ ] **Step 1: Create `JobPosting` model**

```php
// api/app/Modules/HR/Models/JobPosting.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\JobPostingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobPosting extends Model
{
    use HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'posting_number',
        'position_id',
        'department_id',
        'title',
        'description',
        'requirements',
        'employment_type',
        'salary_range_min',
        'salary_range_max',
        'show_salary',
        'slots',
        'posted_at',
        'closes_at',
        'created_by',
    ];

    protected $casts = [
        'employment_type'  => EmploymentType::class,
        'status'           => JobPostingStatus::class,
        'salary_range_min' => 'decimal:2',
        'salary_range_max' => 'decimal:2',
        'show_salary'      => 'boolean',
        'slots'            => 'integer',
        'posted_at'        => 'datetime',
        'closes_at'        => 'datetime',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
```

- [ ] **Step 2: Create `JobApplication` model**

```php
// api/app/Modules/HR/Models/JobApplication.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\ApplicationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    use HasHashId;

    protected $fillable = [
        'application_number',
        'job_posting_id',
        'tracking_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'resume_path',
        'resume_original_name',
        'cover_letter',
        'applied_at',
    ];

    protected $casts = [
        'stage'      => ApplicationStage::class,
        'applied_at' => 'datetime',
    ];

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function convertedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'converted_employee_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(ApplicationInterview::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ApplicationNote::class);
    }
}
```

- [ ] **Step 3: Create `ApplicationInterview` model**

```php
// api/app/Modules/HR/Models/ApplicationInterview.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Enums\InterviewOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationInterview extends Model
{
    use HasHashId;

    protected $fillable = [
        'job_application_id',
        'scheduled_at',
        'location',
        'interviewer_name',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'outcome'      => InterviewOutcome::class,
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'created_by');
    }
}
```

- [ ] **Step 4: Create `ApplicationNote` model**

```php
// api/app/Modules/HR/Models/ApplicationNote.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationNote extends Model
{
    protected $fillable = [
        'job_application_id',
        'user_id',
        'body',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class);
    }
}
```

- [ ] **Step 5: Add document sequence entries to `DocumentSequenceService::CONFIG`**

Add these two entries to the `CONFIG` array in `api/app/Common/Services/DocumentSequenceService.php`, after the `asset_transfer` line:

```php
'job_posting'      => ['prefix' => 'JP',  'reset' => 'monthly', 'pad' => 4],
'job_application'  => ['prefix' => 'JA',  'reset' => 'monthly', 'pad' => 4],
```

- [ ] **Step 6: Add recruitment permissions to `RolePermissionSeeder::permissionCatalog()`**

Add a new `'hr_recruitment'` module key in the `permissionCatalog()` method of `api/database/seeders/RolePermissionSeeder.php`:

```php
'hr_recruitment' => [
    ['slug' => 'hr.recruitment.view',         'name' => 'View Job Postings & Applications'],
    ['slug' => 'hr.recruitment.manage',       'name' => 'Create & Edit Job Postings'],
    ['slug' => 'hr.recruitment.applications', 'name' => 'Manage Applications (stage, notes, interviews)'],
    ['slug' => 'hr.recruitment.hire',         'name' => 'Mark Hired & Convert to Employee'],
],
```

Then add `$this->module('hr_recruitment'),` to the `hr_officer` role's permissions array (after the existing `$this->module('hr_performance'),` line).

- [ ] **Step 7: Add `recruitment` feature toggle to `SettingsSeeder`**

Add `'recruitment' => true,` to the `$modules` array in `api/database/seeders/SettingsSeeder.php` (after the `'notifications'` entry).

- [ ] **Step 8: Re-run seeders and verify**

Run: `cd api && php artisan db:seed --class=RolePermissionSeeder && php artisan db:seed --class=SettingsSeeder`

Expected: No errors. Verify permissions exist:
```bash
php artisan tinker --execute="echo App\Modules\Auth\Models\Permission::where('slug', 'like', 'hr.recruitment%')->count();"
```
Expected output: `4`

- [ ] **Step 9: Commit**

```bash
git add api/app/Modules/HR/Models/JobPosting.php api/app/Modules/HR/Models/JobApplication.php api/app/Modules/HR/Models/ApplicationInterview.php api/app/Modules/HR/Models/ApplicationNote.php api/app/Common/Services/DocumentSequenceService.php api/database/seeders/RolePermissionSeeder.php api/database/seeders/SettingsSeeder.php
git commit -m "feat(recruitment): add models, document sequences, permissions, and feature toggle"
```

---

### Task 3: RecruitmentService + Mail Classes

**Files:**
- Create: `api/app/Modules/HR/Services/RecruitmentService.php`
- Create: `api/app/Modules/HR/Mail/ApplicationReceivedMail.php`
- Create: `api/app/Modules/HR/Mail/InterviewScheduledMail.php`
- Create: `api/resources/views/emails/recruitment/application-received.blade.php`
- Create: `api/resources/views/emails/recruitment/interview-scheduled.blade.php`

**Interfaces:**
- Consumes: Models from Task 2, `DocumentSequenceService::generate()`, `NotificationService::send()`, `ApplicationStage::next()`, `ApplicationStage::isTerminal()`
- Produces: `RecruitmentService` with methods: `createPosting(array): JobPosting`, `updatePosting(JobPosting, array): JobPosting`, `changePostingStatus(JobPosting, JobPostingStatus): void`, `submitApplication(JobPosting, array, UploadedFile): JobApplication`, `advanceStage(JobApplication): void`, `rejectApplication(JobApplication, ?string): void`, `scheduleInterview(JobApplication, array): ApplicationInterview`, `updateInterview(ApplicationInterview, array): void`, `addNote(JobApplication, string, User): ApplicationNote`, `getTrackingInfo(string): ?array`, `getConversionData(JobApplication): array`, `markConverted(JobApplication, Employee): void`

- [ ] **Step 1: Create `RecruitmentService`**

```php
// api/app/Modules/HR/Services/RecruitmentService.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Mail\ApplicationReceivedMail;
use App\Modules\HR\Mail\InterviewScheduledMail;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\ApplicationNote;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RecruitmentService
{
    public function __construct(
        private DocumentSequenceService $sequences,
        private NotificationService $notifications,
    ) {}

    public function createPosting(array $data): JobPosting
    {
        return DB::transaction(function () use ($data) {
            $data['posting_number'] = $this->sequences->generate('job_posting');
            $posting = new JobPosting();
            $posting->fill($data);
            $posting->status = JobPostingStatus::Draft;
            $posting->save();
            return $posting;
        });
    }

    public function updatePosting(JobPosting $posting, array $data): JobPosting
    {
        $posting->update($data);
        return $posting->fresh();
    }

    public function changePostingStatus(JobPosting $posting, JobPostingStatus $newStatus): void
    {
        if ($newStatus === JobPostingStatus::Open && !$posting->posted_at) {
            $posting->posted_at = now();
        }
        $posting->status = $newStatus;
        $posting->save();
    }

    public function submitApplication(JobPosting $posting, array $data, UploadedFile $resume): JobApplication
    {
        return DB::transaction(function () use ($posting, $data, $resume) {
            $path = $resume->store(
                'recruitment/resumes/' . now()->format('Y/m'),
                'local'
            );

            $application = new JobApplication();
            $application->fill([
                'application_number'   => $this->sequences->generate('job_application'),
                'job_posting_id'       => $posting->id,
                'tracking_code'        => $this->generateTrackingCode(),
                'first_name'           => $data['first_name'],
                'last_name'            => $data['last_name'],
                'email'                => $data['email'],
                'phone'                => $data['phone'],
                'resume_path'          => $path,
                'resume_original_name' => $resume->getClientOriginalName(),
                'cover_letter'         => $data['cover_letter'] ?? null,
                'applied_at'           => now(),
            ]);
            $application->stage = ApplicationStage::New;
            $application->save();

            Mail::to($application->email)->send(
                new ApplicationReceivedMail($application, $posting)
            );

            $this->notifyHrNewApplication($application, $posting);

            return $application;
        });
    }

    public function advanceStage(JobApplication $application): void
    {
        $next = $application->stage->next();
        if (!$next) {
            throw new \LogicException("Cannot advance from terminal stage: {$application->stage->value}");
        }
        $application->stage = $next;
        $application->save();
    }

    public function rejectApplication(JobApplication $application, ?string $reason = null): void
    {
        if ($application->stage->isTerminal()) {
            throw new \LogicException("Cannot reject from terminal stage: {$application->stage->value}");
        }
        $application->rejected_at_stage = $application->stage->value;
        $application->rejection_reason = $reason;
        $application->stage = ApplicationStage::Rejected;
        $application->save();
    }

    public function scheduleInterview(JobApplication $application, array $data): ApplicationInterview
    {
        $interview = ApplicationInterview::create($data + [
            'job_application_id' => $application->id,
        ]);

        $application->load('jobPosting');

        Mail::to($application->email)->send(
            new InterviewScheduledMail($application, $interview)
        );

        return $interview;
    }

    public function updateInterview(ApplicationInterview $interview, array $data): void
    {
        if (isset($data['outcome'])) {
            $interview->outcome = $data['outcome'];
        }
        $interview->fill(collect($data)->except('outcome')->toArray());
        $interview->save();
    }

    public function addNote(JobApplication $application, string $body, User $user): ApplicationNote
    {
        return ApplicationNote::create([
            'job_application_id' => $application->id,
            'user_id'            => $user->id,
            'body'               => $body,
        ]);
    }

    public function getTrackingInfo(string $trackingCode): ?array
    {
        $app = JobApplication::with(['jobPosting:id,title', 'interviews' => function ($q) {
            $q->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->limit(1);
        }])->where('tracking_code', $trackingCode)->first();

        if (!$app) {
            return null;
        }

        $interview = $app->interviews->first();
        $statusLabel = $app->stage->publicLabel();
        if ($app->stage === ApplicationStage::Interview && $interview) {
            $statusLabel = 'Interview Scheduled';
        }

        return [
            'tracking_code' => $app->tracking_code,
            'position'      => $app->jobPosting->title,
            'applied_at'    => $app->applied_at->toIso8601String(),
            'status'        => $statusLabel,
            'interview'     => $interview ? [
                'scheduled_at' => $interview->scheduled_at->toIso8601String(),
                'location'     => $interview->location,
            ] : null,
        ];
    }

    public function getConversionData(JobApplication $application): array
    {
        $application->load('jobPosting');
        $posting = $application->jobPosting;

        return [
            'first_name'    => $application->first_name,
            'last_name'     => $application->last_name,
            'email'         => $application->email,
            'phone'         => $application->phone,
            'department_id' => $posting->department?->hash_id,
            'position_id'   => $posting->position?->hash_id,
        ];
    }

    public function markConverted(JobApplication $application, Employee $employee): void
    {
        $application->converted_employee_id = $employee->id;
        $application->save();

        $posting = $application->jobPosting;
        $hiredCount = $posting->applications()
            ->where('stage', ApplicationStage::Hired->value)
            ->whereNotNull('converted_employee_id')
            ->count();

        if ($hiredCount >= $posting->slots) {
            $this->changePostingStatus($posting, JobPostingStatus::Filled);
        }
    }

    private function generateTrackingCode(): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = 'RCT-';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            if (!JobApplication::where('tracking_code', $code)->exists()) {
                return $code;
            }
        }
        throw new \RuntimeException('Failed to generate unique tracking code after 5 attempts');
    }

    private function notifyHrNewApplication(JobApplication $application, JobPosting $posting): void
    {
        $hrUsers = User::whereHas('role', function ($q) {
            $q->whereIn('slug', ['hr_officer', 'hr_manager', 'system_admin']);
        })->where('is_active', true)->get();

        if ($hrUsers->isEmpty()) {
            return;
        }

        $this->notifications->send($hrUsers, 'recruitment.new_application', [
            'title'       => 'New Job Application',
            'message'     => "{$application->full_name} applied for {$posting->title}",
            'link_to'     => "/hr/recruitment/applications/{$application->hash_id}",
            'entity_type' => 'job_application',
            'entity_id'   => $application->hash_id,
        ]);
    }
}
```

- [ ] **Step 2: Create `ApplicationReceivedMail`**

```php
// api/app/Modules/HR/Mail/ApplicationReceivedMail.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationReceivedMail extends Mailable
{
    public function __construct(
        public readonly JobApplication $application,
        public readonly JobPosting $posting,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Application Received — {$this->posting->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.application-received',
            with: [
                'applicantName' => $this->application->full_name,
                'positionTitle' => $this->posting->title,
                'trackingCode'  => $this->application->tracking_code,
                'trackingUrl'   => config('app.frontend_url') . '/careers/track',
            ],
        );
    }
}
```

- [ ] **Step 3: Create `InterviewScheduledMail`**

```php
// api/app/Modules/HR/Mail/InterviewScheduledMail.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InterviewScheduledMail extends Mailable
{
    public function __construct(
        public readonly JobApplication $application,
        public readonly ApplicationInterview $interview,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Interview Scheduled — {$this->application->jobPosting->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.interview-scheduled',
            with: [
                'applicantName'   => $this->application->full_name,
                'positionTitle'   => $this->application->jobPosting->title,
                'scheduledAt'     => $this->interview->scheduled_at->format('F j, Y g:i A'),
                'location'        => $this->interview->location ?? 'To be confirmed',
                'interviewerName' => $this->interview->interviewer_name,
            ],
        );
    }
}
```

- [ ] **Step 4: Create email Blade templates**

```blade
{{-- api/resources/views/emails/recruitment/application-received.blade.php --}}
<x-mail::message>
# Application Received

Dear {{ $applicantName }},

Thank you for your interest in Philippine Ogami Corporation. We have received your application for the **{{ $positionTitle }}** position.

Your tracking code is: **{{ $trackingCode }}**

You can check your application status at any time using this code:

<x-mail::button :url="$trackingUrl">
Track Your Application
</x-mail::button>

We will review your application and get back to you.

Regards,<br>
HR Department<br>
Philippine Ogami Corporation
</x-mail::message>
```

```blade
{{-- api/resources/views/emails/recruitment/interview-scheduled.blade.php --}}
<x-mail::message>
# Interview Scheduled

Dear {{ $applicantName }},

We are pleased to invite you for an interview for the **{{ $positionTitle }}** position.

**Date & Time:** {{ $scheduledAt }}<br>
**Location:** {{ $location }}<br>
**Interviewer:** {{ $interviewerName }}

Please arrive 15 minutes early and bring a valid ID.

**Company Address:**<br>
Philippine Ogami Corporation<br>
FCIE, Dasmariñas, Cavite

Regards,<br>
HR Department<br>
Philippine Ogami Corporation
</x-mail::message>
```

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/HR/Services/RecruitmentService.php api/app/Modules/HR/Mail/ api/resources/views/emails/recruitment/
git commit -m "feat(recruitment): add RecruitmentService and applicant email templates"
```

---

### Task 4: Form Requests + API Resources + Controllers + Routes

**Files:**
- Create: `api/app/Modules/HR/Requests/StoreJobPostingRequest.php`
- Create: `api/app/Modules/HR/Requests/UpdateJobPostingRequest.php`
- Create: `api/app/Modules/HR/Requests/PublicApplicationRequest.php`
- Create: `api/app/Modules/HR/Requests/AdvanceApplicationRequest.php`
- Create: `api/app/Modules/HR/Requests/StoreInterviewRequest.php`
- Create: `api/app/Modules/HR/Resources/JobPostingResource.php`
- Create: `api/app/Modules/HR/Resources/PublicJobPostingResource.php`
- Create: `api/app/Modules/HR/Resources/JobApplicationResource.php`
- Create: `api/app/Modules/HR/Resources/ApplicationInterviewResource.php`
- Create: `api/app/Modules/HR/Resources/ApplicationTrackingResource.php`
- Create: `api/app/Modules/HR/Controllers/RecruitmentPostingController.php`
- Create: `api/app/Modules/HR/Controllers/RecruitmentApplicationController.php`
- Create: `api/app/Modules/HR/Controllers/PublicRecruitmentController.php`
- Modify: `api/app/Modules/HR/routes.php` (add recruitment routes)

**Interfaces:**
- Consumes: `RecruitmentService` from Task 3, Models from Task 2, Enums from Task 1
- Produces: Full REST API at `/api/v1/recruitment/*` (authenticated) and `/api/v1/public/recruitment/*` (unauthenticated)

- [ ] **Step 1: Create Form Requests**

```php
// api/app/Modules/HR/Requests/StoreJobPostingRequest.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.recruitment.manage');
    }

    public function rules(): array
    {
        return [
            'position_id'      => ['nullable', 'exists:positions,id'],
            'department_id'    => ['required', 'exists:departments,id'],
            'title'            => ['required', 'string', 'max:200'],
            'description'      => ['required', 'string'],
            'requirements'     => ['required', 'string'],
            'employment_type'  => ['required', Rule::enum(EmploymentType::class)],
            'salary_range_min' => ['nullable', 'decimal:0,2', 'min:0'],
            'salary_range_max' => ['nullable', 'decimal:0,2', 'min:0', 'gte:salary_range_min'],
            'show_salary'      => ['boolean'],
            'slots'            => ['integer', 'min:1', 'max:100'],
            'closes_at'        => ['nullable', 'date', 'after:today'],
        ];
    }
}
```

```php
// api/app/Modules/HR/Requests/UpdateJobPostingRequest.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.recruitment.manage');
    }

    public function rules(): array
    {
        return [
            'position_id'      => ['nullable', 'exists:positions,id'],
            'department_id'    => ['required', 'exists:departments,id'],
            'title'            => ['required', 'string', 'max:200'],
            'description'      => ['required', 'string'],
            'requirements'     => ['required', 'string'],
            'employment_type'  => ['required', Rule::enum(EmploymentType::class)],
            'salary_range_min' => ['nullable', 'decimal:0,2', 'min:0'],
            'salary_range_max' => ['nullable', 'decimal:0,2', 'min:0', 'gte:salary_range_min'],
            'show_salary'      => ['boolean'],
            'slots'            => ['integer', 'min:1', 'max:100'],
            'closes_at'        => ['nullable', 'date'],
        ];
    }
}
```

```php
// api/app/Modules/HR/Requests/PublicApplicationRequest.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'   => ['required', 'string', 'max:100'],
            'last_name'    => ['required', 'string', 'max:100'],
            'email'        => ['required', 'email', 'max:255'],
            'phone'        => ['required', 'string', 'max:30'],
            'resume'       => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
```

```php
// api/app/Modules/HR/Requests/AdvanceApplicationRequest.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdvanceApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.recruitment.applications');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:advance,reject'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:2000'],
        ];
    }
}
```

```php
// api/app/Modules/HR/Requests/StoreInterviewRequest.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.recruitment.applications');
    }

    public function rules(): array
    {
        return [
            'scheduled_at'     => ['required', 'date', 'after:now'],
            'location'         => ['nullable', 'string', 'max:200'],
            'interviewer_name' => ['required', 'string', 'max:200'],
        ];
    }
}
```

- [ ] **Step 2: Create API Resources**

```php
// api/app/Modules/HR/Resources/JobPostingResource.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobPostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'posting_number'  => $this->posting_number,
            'title'           => $this->title,
            'description'     => $this->description,
            'requirements'    => $this->requirements,
            'employment_type' => $this->employment_type?->value,
            'salary_range_min' => $this->salary_range_min,
            'salary_range_max' => $this->salary_range_max,
            'show_salary'     => $this->show_salary,
            'status'          => $this->status?->value,
            'slots'           => $this->slots,
            'posted_at'       => $this->posted_at?->toIso8601String(),
            'closes_at'       => $this->closes_at?->toIso8601String(),
            'position'        => $this->whenLoaded('position', fn () => [
                'id'    => $this->position->hash_id,
                'title' => $this->position->title,
            ]),
            'department' => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->hash_id,
                'name' => $this->department->name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->hash_id,
                'name' => $this->createdBy->name,
            ]),
            'application_count' => $this->when(
                $this->applications_count !== null,
                $this->applications_count
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

```php
// api/app/Modules/HR/Resources/PublicJobPostingResource.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicJobPostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'posting_number'  => $this->posting_number,
            'title'           => $this->title,
            'description'     => $this->description,
            'requirements'    => $this->requirements,
            'employment_type' => $this->employment_type?->value,
            'salary_range'    => $this->show_salary ? [
                'min' => $this->salary_range_min,
                'max' => $this->salary_range_max,
            ] : null,
            'department' => [
                'id'   => $this->department->hash_id,
                'name' => $this->department->name,
            ],
            'posted_at'  => $this->posted_at?->toIso8601String(),
            'closes_at'  => $this->closes_at?->toIso8601String(),
        ];
    }
}
```

```php
// api/app/Modules/HR/Resources/JobApplicationResource.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'application_number' => $this->application_number,
            'tracking_code'      => $this->tracking_code,
            'first_name'         => $this->first_name,
            'last_name'          => $this->last_name,
            'full_name'          => $this->full_name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'cover_letter'       => $this->cover_letter,
            'stage'              => $this->stage?->value,
            'stage_label'        => $this->stage?->label(),
            'rejected_at_stage'  => $this->rejected_at_stage,
            'rejection_reason'   => $this->rejection_reason,
            'applied_at'         => $this->applied_at?->toIso8601String(),
            'job_posting'        => new JobPostingResource($this->whenLoaded('jobPosting')),
            'interviews'         => ApplicationInterviewResource::collection($this->whenLoaded('interviews')),
            'notes'              => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($n) => [
                'id'   => $n->id,
                'body' => $n->body,
                'user' => ['id' => $n->user->hash_id, 'name' => $n->user->name],
                'created_at' => $n->created_at?->toIso8601String(),
            ])),
            'converted_employee' => $this->whenLoaded('convertedEmployee', fn () => [
                'id'          => $this->convertedEmployee->hash_id,
                'employee_no' => $this->convertedEmployee->employee_no,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

```php
// api/app/Modules/HR/Resources/ApplicationInterviewResource.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationInterviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'scheduled_at'     => $this->scheduled_at?->toIso8601String(),
            'location'         => $this->location,
            'interviewer_name' => $this->interviewer_name,
            'notes'            => $this->notes,
            'outcome'          => $this->outcome?->value,
            'created_by'       => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->hash_id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

```php
// api/app/Modules/HR/Resources/ApplicationTrackingResource.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
```

- [ ] **Step 3: Create `PublicRecruitmentController`**

```php
// api/app/Modules/HR/Controllers/PublicRecruitmentController.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Requests\PublicApplicationRequest;
use App\Modules\HR\Resources\PublicJobPostingResource;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicRecruitmentController extends Controller
{
    public function __construct(private RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobPosting::with('department')
            ->where('status', JobPostingStatus::Open)
            ->orderByDesc('posted_at');

        if ($request->filled('department_id')) {
            $decoded = app('hashids')->decode($request->input('department_id'));
            if (!empty($decoded)) {
                $query->where('department_id', $decoded[0]);
            }
        }

        return PublicJobPostingResource::collection($query->paginate(12));
    }

    public function show(JobPosting $jobPosting): PublicJobPostingResource
    {
        if ($jobPosting->status !== JobPostingStatus::Open) {
            abort(404);
        }

        return new PublicJobPostingResource($jobPosting->load('department'));
    }

    public function apply(PublicApplicationRequest $request, JobPosting $jobPosting): JsonResponse
    {
        if ($jobPosting->status !== JobPostingStatus::Open) {
            abort(422, 'This position is no longer accepting applications.');
        }

        if ($jobPosting->closes_at && $jobPosting->closes_at->isPast()) {
            abort(422, 'The application deadline has passed.');
        }

        $application = $this->service->submitApplication(
            $jobPosting,
            $request->validated(),
            $request->file('resume'),
        );

        return response()->json([
            'tracking_code' => $application->tracking_code,
            'message'       => 'Application submitted successfully.',
        ], 201);
    }

    public function track(string $trackingCode): JsonResponse
    {
        $info = $this->service->getTrackingInfo($trackingCode);

        if (!$info) {
            abort(404, 'Application not found.');
        }

        return response()->json(['data' => $info]);
    }
}
```

- [ ] **Step 4: Create `RecruitmentPostingController`**

```php
// api/app/Modules/HR/Controllers/RecruitmentPostingController.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Requests\StoreJobPostingRequest;
use App\Modules\HR\Requests\UpdateJobPostingRequest;
use App\Modules\HR\Resources\JobPostingResource;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecruitmentPostingController extends Controller
{
    public function __construct(private RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobPosting::with(['department', 'position'])
            ->withCount('applications')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return JobPostingResource::collection($query->paginate(15));
    }

    public function store(StoreJobPostingRequest $request): JobPostingResource
    {
        $posting = $this->service->createPosting(
            $request->validated() + ['created_by' => $request->user()->id]
        );

        return new JobPostingResource($posting->load(['department', 'position']));
    }

    public function show(JobPosting $jobPosting): JobPostingResource
    {
        return new JobPostingResource(
            $jobPosting->load(['department', 'position', 'createdBy'])
                ->loadCount('applications')
        );
    }

    public function update(UpdateJobPostingRequest $request, JobPosting $jobPosting): JobPostingResource
    {
        $posting = $this->service->updatePosting($jobPosting, $request->validated());
        return new JobPostingResource($posting->load(['department', 'position']));
    }

    public function destroy(JobPosting $jobPosting): JsonResponse
    {
        $jobPosting->delete();
        return response()->json(null, 204);
    }

    public function changeStatus(Request $request, JobPosting $jobPosting): JobPostingResource
    {
        $request->validate(['status' => ['required', 'in:open,closed,filled']]);
        $this->service->changePostingStatus($jobPosting, JobPostingStatus::from($request->input('status')));
        return new JobPostingResource($jobPosting->fresh()->load(['department', 'position']));
    }
}
```

- [ ] **Step 5: Create `RecruitmentApplicationController`**

```php
// api/app/Modules/HR/Controllers/RecruitmentApplicationController.php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Requests\AdvanceApplicationRequest;
use App\Modules\HR\Requests\StoreInterviewRequest;
use App\Modules\HR\Resources\ApplicationInterviewResource;
use App\Modules\HR\Resources\JobApplicationResource;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class RecruitmentApplicationController extends Controller
{
    public function __construct(private RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobApplication::with(['jobPosting:id,title,posting_number'])
            ->orderByDesc('applied_at');

        if ($request->filled('stage')) {
            $query->where('stage', $request->input('stage'));
        }
        if ($request->filled('job_posting_id')) {
            $decoded = app('hashids')->decode($request->input('job_posting_id'));
            if (!empty($decoded)) {
                $query->where('job_posting_id', $decoded[0]);
            }
        }

        return JobApplicationResource::collection($query->paginate(20));
    }

    public function show(JobApplication $jobApplication): JobApplicationResource
    {
        return new JobApplicationResource(
            $jobApplication->load(['jobPosting.department', 'interviews.createdBy', 'notes.user', 'convertedEmployee'])
        );
    }

    public function changeStage(AdvanceApplicationRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        if ($request->input('action') === 'reject') {
            $this->service->rejectApplication($jobApplication, $request->input('rejection_reason'));
        } else {
            $this->service->advanceStage($jobApplication);
        }

        return new JobApplicationResource($jobApplication->fresh()->load('jobPosting'));
    }

    public function storeInterview(StoreInterviewRequest $request, JobApplication $jobApplication): ApplicationInterviewResource
    {
        $interview = $this->service->scheduleInterview($jobApplication, $request->validated() + [
            'created_by' => $request->user()->id,
        ]);

        return new ApplicationInterviewResource($interview);
    }

    public function updateInterview(Request $request, ApplicationInterview $interview): ApplicationInterviewResource
    {
        $data = $request->validate([
            'notes'   => ['nullable', 'string'],
            'outcome' => ['nullable', 'in:pending,passed,failed'],
        ]);

        $this->service->updateInterview($interview, $data);
        return new ApplicationInterviewResource($interview->fresh());
    }

    public function storeNote(Request $request, JobApplication $jobApplication): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $note = $this->service->addNote($jobApplication, $data['body'], $request->user());

        return response()->json([
            'data' => [
                'id'   => $note->id,
                'body' => $note->body,
                'user' => ['id' => $request->user()->hash_id, 'name' => $request->user()->name],
                'created_at' => $note->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function downloadResume(JobApplication $jobApplication): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!Storage::disk('local')->exists($jobApplication->resume_path)) {
            abort(404, 'Resume file not found.');
        }

        return Storage::disk('local')->download(
            $jobApplication->resume_path,
            $jobApplication->resume_original_name
        );
    }

    public function conversionData(JobApplication $jobApplication): JsonResponse
    {
        if ($jobApplication->stage->value !== 'hired') {
            abort(422, 'Only hired applicants can be converted.');
        }

        return response()->json(['data' => $this->service->getConversionData($jobApplication)]);
    }
}
```

- [ ] **Step 6: Add routes to `api/app/Modules/HR/routes.php`**

Append the following at the end of the file, BEFORE the final `});` closing the main `Route::middleware(['auth:sanctum', 'feature:hr'])` group:

```php
    // Recruitment — HR-facing (authenticated)
    Route::middleware('feature:recruitment')->prefix('recruitment')->group(function () {
        // Job Postings
        Route::prefix('postings')->group(function () {
            Route::get('/',            [RecruitmentPostingController::class, 'index'])->middleware('permission:hr.recruitment.view');
            Route::post('/',           [RecruitmentPostingController::class, 'store'])->middleware('permission:hr.recruitment.manage');
            Route::get('/{jobPosting}',  [RecruitmentPostingController::class, 'show'])->middleware('permission:hr.recruitment.view');
            Route::put('/{jobPosting}',  [RecruitmentPostingController::class, 'update'])->middleware('permission:hr.recruitment.manage');
            Route::delete('/{jobPosting}', [RecruitmentPostingController::class, 'destroy'])->middleware('permission:hr.recruitment.manage');
            Route::patch('/{jobPosting}/status', [RecruitmentPostingController::class, 'changeStatus'])->middleware('permission:hr.recruitment.manage');
        });

        // Applications
        Route::prefix('applications')->group(function () {
            Route::get('/',                     [RecruitmentApplicationController::class, 'index'])->middleware('permission:hr.recruitment.view');
            Route::get('/{jobApplication}',     [RecruitmentApplicationController::class, 'show'])->middleware('permission:hr.recruitment.view');
            Route::patch('/{jobApplication}/stage', [RecruitmentApplicationController::class, 'changeStage'])->middleware('permission:hr.recruitment.applications');
            Route::post('/{jobApplication}/interviews', [RecruitmentApplicationController::class, 'storeInterview'])->middleware('permission:hr.recruitment.applications');
            Route::post('/{jobApplication}/notes',      [RecruitmentApplicationController::class, 'storeNote'])->middleware('permission:hr.recruitment.applications');
            Route::get('/{jobApplication}/resume',      [RecruitmentApplicationController::class, 'downloadResume'])->middleware('permission:hr.recruitment.view');
            Route::get('/{jobApplication}/convert',     [RecruitmentApplicationController::class, 'conversionData'])->middleware('permission:hr.recruitment.hire');
        });

        // Interview update (standalone — no application nesting)
        Route::patch('/interviews/{interview}', [RecruitmentApplicationController::class, 'updateInterview'])->middleware('permission:hr.recruitment.applications');
    });
```

Add public recruitment routes **outside** the authenticated group, at the very end of the file:

```php
// Recruitment — public-facing (no auth)
Route::prefix('public/recruitment')->middleware('throttle:30,1')->group(function () {
    Route::get('/job-postings',              [PublicRecruitmentController::class, 'index']);
    Route::get('/job-postings/{jobPosting}', [PublicRecruitmentController::class, 'show']);
    Route::post('/job-postings/{jobPosting}/apply', [PublicRecruitmentController::class, 'apply'])
        ->middleware('throttle:10,1');
    Route::get('/applications/track/{trackingCode}', [PublicRecruitmentController::class, 'track']);
});
```

Add the use statements at the top of the file:

```php
use App\Modules\HR\Controllers\PublicRecruitmentController;
use App\Modules\HR\Controllers\RecruitmentApplicationController;
use App\Modules\HR\Controllers\RecruitmentPostingController;
```

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/HR/Requests/ api/app/Modules/HR/Resources/ api/app/Modules/HR/Controllers/PublicRecruitmentController.php api/app/Modules/HR/Controllers/RecruitmentPostingController.php api/app/Modules/HR/Controllers/RecruitmentApplicationController.php api/app/Modules/HR/routes.php
git commit -m "feat(recruitment): add requests, resources, controllers, and routes"
```

---

### Task 5: Backend Feature Tests

**Files:**
- Create: `api/tests/Feature/HR/RecruitmentPostingTest.php`
- Create: `api/tests/Feature/HR/RecruitmentApplicationTest.php`
- Create: `api/tests/Feature/HR/PublicRecruitmentTest.php`

**Interfaces:**
- Consumes: All backend code from Tasks 1–4
- Produces: Test coverage for posting CRUD, application pipeline, public API, and tracking

- [ ] **Step 1: Create `RecruitmentPostingTest`**

```php
// api/tests/Feature/HR/RecruitmentPostingTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Position;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecruitmentPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $hrUser;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $this->hrUser = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $empRole = Role::where('slug', 'employee')->firstOrFail();
        $this->employee = User::factory()->create(['role_id' => $empRole->id, 'is_active' => true]);
    }

    public function test_hr_can_create_job_posting(): void
    {
        $dept = Department::factory()->create();
        $position = Position::factory()->create(['department_id' => $dept->id]);

        $response = $this->actingAs($this->hrUser)->postJson('/api/v1/hr/recruitment/postings', [
            'title'           => 'Injection Molding Operator',
            'department_id'   => $dept->id,
            'position_id'     => $position->id,
            'description'     => 'Operate injection molding machines.',
            'requirements'    => 'At least 1 year experience.',
            'employment_type' => 'regular',
            'slots'           => 2,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Injection Molding Operator');
        $response->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('job_postings', [
            'title'  => 'Injection Molding Operator',
            'status' => 'draft',
            'slots'  => 2,
        ]);
    }

    public function test_hr_can_list_postings(): void
    {
        $dept = Department::factory()->create();
        $posting = new JobPosting();
        $posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'Test Position',
            'department_id'   => $dept->id,
            'description'     => 'Test description',
            'requirements'    => 'Test requirements',
            'employment_type' => 'regular',
            'created_by'      => $this->hrUser->id,
        ]);
        $posting->status = JobPostingStatus::Open;
        $posting->save();

        $response = $this->actingAs($this->hrUser)->getJson('/api/v1/hr/recruitment/postings');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_hr_can_change_posting_status_to_open(): void
    {
        $dept = Department::factory()->create();
        $posting = new JobPosting();
        $posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'QC Inspector',
            'department_id'   => $dept->id,
            'description'     => 'Quality control.',
            'requirements'    => 'Experience required.',
            'employment_type' => 'regular',
            'created_by'      => $this->hrUser->id,
        ]);
        $posting->status = JobPostingStatus::Draft;
        $posting->save();

        $response = $this->actingAs($this->hrUser)->patchJson("/api/v1/hr/recruitment/postings/{$posting->hash_id}/status", [
            'status' => 'open',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'open');
        $this->assertNotNull($posting->fresh()->posted_at);
    }

    public function test_employee_cannot_access_recruitment(): void
    {
        $response = $this->actingAs($this->employee)->getJson('/api/v1/hr/recruitment/postings');
        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Create `PublicRecruitmentTest`**

```php
// api/tests/Feature/HR/PublicRecruitmentTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicRecruitmentTest extends TestCase
{
    use RefreshDatabase;

    private JobPosting $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $user = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $dept = Department::factory()->create();
        $this->posting = new JobPosting();
        $this->posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'Molding Operator',
            'department_id'   => $dept->id,
            'description'     => 'Operate machines.',
            'requirements'    => '1 year exp.',
            'employment_type' => 'regular',
            'created_by'      => $user->id,
            'posted_at'       => now(),
        ]);
        $this->posting->status = JobPostingStatus::Open;
        $this->posting->save();
    }

    public function test_public_can_list_open_postings(): void
    {
        $response = $this->getJson('/api/v1/hr/public/recruitment/job-postings');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Molding Operator');
    }

    public function test_public_can_view_single_posting(): void
    {
        $response = $this->getJson("/api/v1/hr/public/recruitment/job-postings/{$this->posting->hash_id}");
        $response->assertOk();
        $response->assertJsonPath('data.title', 'Molding Operator');
    }

    public function test_public_can_submit_application(): void
    {
        Storage::fake('local');
        Mail::fake();

        $response = $this->postJson("/api/v1/hr/public/recruitment/job-postings/{$this->posting->hash_id}/apply", [
            'first_name'   => 'Juan',
            'last_name'    => 'Dela Cruz',
            'email'        => 'juan@example.com',
            'phone'        => '09171234567',
            'resume'       => UploadedFile::fake()->create('resume.pdf', 1024, 'application/pdf'),
            'cover_letter' => 'I am very interested.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['tracking_code', 'message']);
        $this->assertDatabaseHas('job_applications', [
            'email' => 'juan@example.com',
            'stage' => 'new',
        ]);
    }

    public function test_public_cannot_apply_to_closed_posting(): void
    {
        $this->posting->status = JobPostingStatus::Closed;
        $this->posting->save();

        Storage::fake('local');

        $response = $this->postJson("/api/v1/hr/public/recruitment/job-postings/{$this->posting->hash_id}/apply", [
            'first_name' => 'Test',
            'last_name'  => 'User',
            'email'      => 'test@example.com',
            'phone'      => '09170000000',
            'resume'     => UploadedFile::fake()->create('resume.pdf', 1024, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_public_can_track_application(): void
    {
        Storage::fake('local');
        Mail::fake();

        $applyResponse = $this->postJson("/api/v1/hr/public/recruitment/job-postings/{$this->posting->hash_id}/apply", [
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
            'email'      => 'maria@example.com',
            'phone'      => '09171111111',
            'resume'     => UploadedFile::fake()->create('cv.pdf', 512, 'application/pdf'),
        ]);

        $code = $applyResponse->json('tracking_code');

        $trackResponse = $this->getJson("/api/v1/hr/public/recruitment/applications/track/{$code}");
        $trackResponse->assertOk();
        $trackResponse->assertJsonPath('data.status', 'Application Received');
        $trackResponse->assertJsonPath('data.position', 'Molding Operator');
    }

    public function test_invalid_tracking_code_returns_404(): void
    {
        $response = $this->getJson('/api/v1/hr/public/recruitment/applications/track/INVALID');
        $response->assertStatus(404);
    }
}
```

- [ ] **Step 3: Create `RecruitmentApplicationTest`**

```php
// api/tests/Feature/HR/RecruitmentApplicationTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecruitmentApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $hrUser;
    private JobPosting $posting;
    private JobApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $this->hrUser = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $dept = Department::factory()->create();
        $this->posting = new JobPosting();
        $this->posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'Test Position',
            'department_id'   => $dept->id,
            'description'     => 'Desc',
            'requirements'    => 'Reqs',
            'employment_type' => 'regular',
            'created_by'      => $this->hrUser->id,
            'posted_at'       => now(),
        ]);
        $this->posting->status = JobPostingStatus::Open;
        $this->posting->save();

        $this->application = new JobApplication();
        $this->application->fill([
            'application_number'   => 'JA-T-' . substr(uniqid(), -5),
            'job_posting_id'       => $this->posting->id,
            'tracking_code'        => 'RCT-TEST01',
            'first_name'           => 'Juan',
            'last_name'            => 'Test',
            'email'                => 'juan@test.com',
            'phone'                => '09170000000',
            'resume_path'          => 'recruitment/resumes/test.pdf',
            'resume_original_name' => 'test.pdf',
            'applied_at'           => now(),
        ]);
        $this->application->stage = ApplicationStage::New;
        $this->application->save();
    }

    public function test_hr_can_list_applications(): void
    {
        $response = $this->actingAs($this->hrUser)->getJson('/api/v1/hr/recruitment/applications');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_hr_can_advance_application_stage(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.stage', 'screening');
    }

    public function test_hr_can_reject_application(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'reject',
                'rejection_reason' => 'Does not meet qualifications.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.stage', 'rejected');
        $this->assertDatabaseHas('job_applications', [
            'id'                => $this->application->id,
            'rejected_at_stage' => 'new',
        ]);
    }

    public function test_hr_can_schedule_interview(): void
    {
        $this->application->stage = ApplicationStage::Interview;
        $this->application->save();

        $response = $this->actingAs($this->hrUser)
            ->postJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/interviews", [
                'scheduled_at'     => now()->addDays(3)->toIso8601String(),
                'location'         => 'HR Office, 2nd Floor',
                'interviewer_name' => 'Maria Santos',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.interviewer_name', 'Maria Santos');
        $this->assertDatabaseCount('application_interviews', 1);
    }

    public function test_hr_can_add_note(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->postJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/notes", [
                'body' => 'Strong candidate, proceed to screening.',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('application_notes', [
            'body'    => 'Strong candidate, proceed to screening.',
            'user_id' => $this->hrUser->id,
        ]);
    }

    public function test_cannot_advance_terminal_stage(): void
    {
        $this->application->stage = ApplicationStage::Hired;
        $this->application->save();

        $response = $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ]);

        $response->assertStatus(500);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `cd api && php artisan test --filter='RecruitmentPosting|RecruitmentApplication|PublicRecruitment' -v`

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add api/tests/Feature/HR/RecruitmentPostingTest.php api/tests/Feature/HR/RecruitmentApplicationTest.php api/tests/Feature/HR/PublicRecruitmentTest.php
git commit -m "test(recruitment): add feature tests for postings, applications, and public API"
```

---

### Task 6: Frontend — Types + API Client + Routes

**Files:**
- Create: `spa/src/types/recruitment.ts`
- Create: `spa/src/api/recruitment.ts`
- Create: `spa/src/api/public-recruitment.ts`
- Create: `spa/src/routes/careersRoutes.tsx`
- Modify: `spa/src/routes/hrRoutes.tsx` (add `/hr/recruitment/*` routes)
- Modify: `spa/src/App.tsx` (add `careersRoutes`)

**Interfaces:**
- Consumes: API endpoints from Task 4
- Produces: TypeScript types, API functions, route definitions for all recruitment pages

- [ ] **Step 1: Create TypeScript types**

```typescript
// spa/src/types/recruitment.ts
export type JobPostingStatus = 'draft' | 'open' | 'closed' | 'filled';
export type ApplicationStage = 'new' | 'screening' | 'interview' | 'offer' | 'hired' | 'rejected';
export type InterviewOutcome = 'pending' | 'passed' | 'failed';
export type EmploymentType = 'regular' | 'probationary' | 'contractual' | 'project_based';

export interface JobPosting {
  id: string;
  posting_number: string;
  title: string;
  description: string;
  requirements: string;
  employment_type: EmploymentType;
  salary_range_min: string | null;
  salary_range_max: string | null;
  show_salary: boolean;
  status: JobPostingStatus;
  slots: number;
  posted_at: string | null;
  closes_at: string | null;
  position?: { id: string; title: string } | null;
  department?: { id: string; name: string } | null;
  created_by?: { id: string; name: string } | null;
  application_count?: number;
  created_at: string;
  updated_at: string;
}

export interface PublicJobPosting {
  id: string;
  posting_number: string;
  title: string;
  description: string;
  requirements: string;
  employment_type: EmploymentType;
  salary_range: { min: string; max: string } | null;
  department: { id: string; name: string };
  posted_at: string;
  closes_at: string | null;
}

export interface JobApplication {
  id: string;
  application_number: string;
  tracking_code: string;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string;
  phone: string;
  cover_letter: string | null;
  stage: ApplicationStage;
  stage_label: string;
  rejected_at_stage: string | null;
  rejection_reason: string | null;
  applied_at: string;
  job_posting?: JobPosting;
  interviews?: ApplicationInterview[];
  notes?: ApplicationNote[];
  converted_employee?: { id: string; employee_no: string } | null;
  created_at: string;
  updated_at: string;
}

export interface ApplicationInterview {
  id: string;
  scheduled_at: string;
  location: string | null;
  interviewer_name: string;
  notes: string | null;
  outcome: InterviewOutcome | null;
  created_by?: { id: string; name: string };
  created_at: string;
}

export interface ApplicationNote {
  id: number;
  body: string;
  user: { id: string; name: string };
  created_at: string;
}

export interface TrackingInfo {
  tracking_code: string;
  position: string;
  applied_at: string;
  status: string;
  interview: { scheduled_at: string; location: string } | null;
}

export interface CreateJobPostingData {
  title: string;
  department_id: string;
  position_id?: string | null;
  description: string;
  requirements: string;
  employment_type: EmploymentType;
  salary_range_min?: string | null;
  salary_range_max?: string | null;
  show_salary?: boolean;
  slots?: number;
  closes_at?: string | null;
}
```

- [ ] **Step 2: Create HR-facing API client**

```typescript
// spa/src/api/recruitment.ts
import client from './client';
import type { Paginated } from '@/types/common';
import type {
  JobPosting,
  JobApplication,
  ApplicationInterview,
  ApplicationNote,
  CreateJobPostingData,
} from '@/types/recruitment';

const BASE = '/recruitment';

export const recruitmentApi = {
  // Postings
  listPostings: (params?: Record<string, unknown>) =>
    client.get<Paginated<JobPosting>>(`${BASE}/postings`, { params }),
  showPosting: (id: string) =>
    client.get<{ data: JobPosting }>(`${BASE}/postings/${id}`),
  createPosting: (data: CreateJobPostingData) =>
    client.post<{ data: JobPosting }>(`${BASE}/postings`, data),
  updatePosting: (id: string, data: CreateJobPostingData) =>
    client.put<{ data: JobPosting }>(`${BASE}/postings/${id}`, data),
  deletePosting: (id: string) =>
    client.delete(`${BASE}/postings/${id}`),
  changePostingStatus: (id: string, status: string) =>
    client.patch<{ data: JobPosting }>(`${BASE}/postings/${id}/status`, { status }),

  // Applications
  listApplications: (params?: Record<string, unknown>) =>
    client.get<Paginated<JobApplication>>(`${BASE}/applications`, { params }),
  showApplication: (id: string) =>
    client.get<{ data: JobApplication }>(`${BASE}/applications/${id}`),
  changeStage: (id: string, data: { action: 'advance' | 'reject'; rejection_reason?: string }) =>
    client.patch<{ data: JobApplication }>(`${BASE}/applications/${id}/stage`, data),
  scheduleInterview: (id: string, data: { scheduled_at: string; location?: string; interviewer_name: string }) =>
    client.post<{ data: ApplicationInterview }>(`${BASE}/applications/${id}/interviews`, data),
  updateInterview: (id: string, data: { notes?: string; outcome?: string }) =>
    client.patch<{ data: ApplicationInterview }>(`${BASE}/interviews/${id}`, data),
  addNote: (id: string, body: string) =>
    client.post<{ data: ApplicationNote }>(`${BASE}/applications/${id}/notes`, { body }),
  downloadResume: (id: string) =>
    client.get(`${BASE}/applications/${id}/resume`, { responseType: 'blob' }),
  getConversionData: (id: string) =>
    client.get<{ data: Record<string, string | null> }>(`${BASE}/applications/${id}/convert`),
};
```

- [ ] **Step 3: Create public API client**

```typescript
// spa/src/api/public-recruitment.ts
import axios from 'axios';
import type { Paginated } from '@/types/common';
import type { PublicJobPosting, TrackingInfo } from '@/types/recruitment';

const publicClient = axios.create({
  baseURL: '/api/v1/hr/public/recruitment',
  headers: { Accept: 'application/json' },
});

export const publicRecruitmentApi = {
  listPostings: (params?: Record<string, unknown>) =>
    publicClient.get<Paginated<PublicJobPosting>>('/job-postings', { params }),
  showPosting: (id: string) =>
    publicClient.get<{ data: PublicJobPosting }>(`/job-postings/${id}`),
  apply: (postingId: string, formData: FormData) =>
    publicClient.post<{ tracking_code: string; message: string }>(`/job-postings/${postingId}/apply`, formData),
  track: (code: string) =>
    publicClient.get<{ data: TrackingInfo }>(`/applications/track/${code}`),
};
```

- [ ] **Step 4: Create `careersRoutes.tsx`**

```tsx
// spa/src/routes/careersRoutes.tsx
import { lazy } from 'react';
import { Route } from 'react-router-dom';

const CareersPage = lazy(() => import('@/pages/careers'));
const JobPostingDetailPage = lazy(() => import('@/pages/careers/detail'));
const ApplicationTrackPage = lazy(() => import('@/pages/careers/track'));

export const careersRoutes = (
  <>
    <Route path="/careers" element={<CareersPage />} />
    <Route path="/careers/track" element={<ApplicationTrackPage />} />
    <Route path="/careers/:id" element={<JobPostingDetailPage />} />
  </>
);
```

- [ ] **Step 5: Add recruitment routes to `hrRoutes.tsx`**

Add lazy imports at the top:

```tsx
// Recruitment
const RecruitmentDashboard = lazy(() => import('@/pages/hr/recruitment'));
const PostingsListPage = lazy(() => import('@/pages/hr/recruitment/postings'));
const PostingCreatePage = lazy(() => import('@/pages/hr/recruitment/postings/create'));
const PostingDetailPage = lazy(() => import('@/pages/hr/recruitment/postings/detail'));
const PostingEditPage = lazy(() => import('@/pages/hr/recruitment/postings/edit'));
const ApplicationsListPage = lazy(() => import('@/pages/hr/recruitment/applications'));
const ApplicationDetailPage = lazy(() => import('@/pages/hr/recruitment/applications/detail'));
```

Add the route block inside the existing `<>` fragment, after the Training Matrix routes:

```tsx
{/* Recruitment */}
<Route element={<ModuleGuard module="recruitment" />}>
  <Route path="/hr/recruitment"
    element={<PermissionGuard permission="hr.recruitment.view"><RecruitmentDashboard /></PermissionGuard>} />
  <Route path="/hr/recruitment/postings"
    element={<PermissionGuard permission="hr.recruitment.view"><PostingsListPage /></PermissionGuard>} />
  <Route path="/hr/recruitment/postings/create"
    element={<PermissionGuard permission="hr.recruitment.manage"><PostingCreatePage /></PermissionGuard>} />
  <Route path="/hr/recruitment/postings/:id"
    element={<PermissionGuard permission="hr.recruitment.view"><PostingDetailPage /></PermissionGuard>} />
  <Route path="/hr/recruitment/postings/:id/edit"
    element={<PermissionGuard permission="hr.recruitment.manage"><PostingEditPage /></PermissionGuard>} />
  <Route path="/hr/recruitment/applications"
    element={<PermissionGuard permission="hr.recruitment.view"><ApplicationsListPage /></PermissionGuard>} />
  <Route path="/hr/recruitment/applications/:id"
    element={<PermissionGuard permission="hr.recruitment.view"><ApplicationDetailPage /></PermissionGuard>} />
</Route>
```

- [ ] **Step 6: Add `careersRoutes` to `App.tsx`**

Add import: `import { careersRoutes } from '@/routes/careersRoutes';`

Add `{careersRoutes}` next to the existing `{landingRoutes}` line (both are public, no auth guard).

- [ ] **Step 7: Add Recruitment to Sidebar**

In `spa/src/components/layout/Sidebar.tsx`, add to the `Human Resources` section's items array (after the Performance Reviews entry):

```tsx
{ to: '/hr/recruitment',              label: 'Recruitment',     icon: UserPlus, feature: 'recruitment', permission: 'hr.recruitment.view' },
```

Make sure `UserPlus` is imported from `lucide-react` at the top of the file (check if it's already imported — if not, add it).

- [ ] **Step 8: Update landing page footer Careers link**

In `spa/src/pages/landing/components/LandingFooter.tsx`, change:
```tsx
{ label: 'Careers', href: '#' },
```
to:
```tsx
{ label: 'Careers', href: '/careers' },
```

- [ ] **Step 9: Commit**

```bash
git add spa/src/types/recruitment.ts spa/src/api/recruitment.ts spa/src/api/public-recruitment.ts spa/src/routes/careersRoutes.tsx spa/src/routes/hrRoutes.tsx spa/src/App.tsx spa/src/components/layout/Sidebar.tsx spa/src/pages/landing/components/LandingFooter.tsx
git commit -m "feat(recruitment): add frontend types, API clients, routes, and sidebar entry"
```

---

### Task 7: Public Careers Pages (SPA)

**Files:**
- Create: `spa/src/pages/careers/index.tsx` (CareersPage — job listing)
- Create: `spa/src/pages/careers/detail.tsx` (JobPostingDetailPage + inline apply form)
- Create: `spa/src/pages/careers/track.tsx` (ApplicationTrackPage)

**Interfaces:**
- Consumes: `publicRecruitmentApi` from Task 6, `PublicJobPosting`, `TrackingInfo` types
- Produces: 3 public-facing pages at `/careers`, `/careers/:id`, `/careers/track`

These pages must follow the landing page aesthetic (monochrome, Bricolage Grotesque font) and reuse `LandingNav`/`LandingFooter` components. Read `docs/PATTERNS.md` for list page and form patterns. Read existing landing page sections for styling reference.

Build each page following PATTERNS.md templates — handle loading/error/empty/data states. Use TanStack Query for data fetching. Use Zod + React Hook Form for the application form. Show success state with tracking code after submit.

**Key implementation details:**
- **CareersPage**: Grid of job cards, department filter dropdown, paginated. Link each card to `/careers/{id}`.
- **JobPostingDetailPage**: Full description, requirements, employment type, salary (if shown). Inline application form below the job details. On successful submit, show tracking code and hide form.
- **ApplicationTrackPage**: Single input for tracking code. On submit, show stepper/timeline of current stage + interview info if available.

Step-by-step instructions are intentionally lighter here — follow the existing page patterns in the codebase. The subagent should read `docs/PATTERNS.md` and at least one existing landing section (`spa/src/pages/landing/sections/ContactSection.tsx`) for the visual style before building.

- [ ] **Step 1: Build CareersPage** — job listing with department filter
- [ ] **Step 2: Build JobPostingDetailPage** — detail view + inline application form with file upload
- [ ] **Step 3: Build ApplicationTrackPage** — tracking code input + status display
- [ ] **Step 4: Verify pages render** — start dev server, navigate to `/careers`, submit test application, track it
- [ ] **Step 5: Commit**

```bash
git add spa/src/pages/careers/
git commit -m "feat(recruitment): add public careers pages (listing, detail+apply, tracking)"
```

---

### Task 8: HR Recruitment Pages (SPA)

**Files:**
- Create: `spa/src/pages/hr/recruitment/index.tsx` (RecruitmentDashboard)
- Create: `spa/src/pages/hr/recruitment/postings/index.tsx` (PostingsListPage)
- Create: `spa/src/pages/hr/recruitment/postings/create.tsx` (PostingFormPage)
- Create: `spa/src/pages/hr/recruitment/postings/detail.tsx` (PostingDetailPage)
- Create: `spa/src/pages/hr/recruitment/postings/edit.tsx` (PostingEditPage — reuse form from create)
- Create: `spa/src/pages/hr/recruitment/applications/index.tsx` (ApplicationsListPage)
- Create: `spa/src/pages/hr/recruitment/applications/detail.tsx` (ApplicationDetailPage)

**Interfaces:**
- Consumes: `recruitmentApi` from Task 6, all recruitment types
- Produces: 7 HR-facing pages at `/hr/recruitment/*`

These pages follow the ERP design system (not the landing page aesthetic). Read `docs/PATTERNS.md` and `docs/DESIGN-SYSTEM.md` for the correct patterns. Use the ERP's existing `PageHeader`, `DataTable`, `Chip`, `Button`, `Input`, `Dialog` components.

**Key implementation details:**
- **RecruitmentDashboard**: Stage breakdown cards (count per stage), recent applications list, open postings count. Quick link to create posting.
- **PostingsListPage**: Table with columns: posting number, title, department, status (Chip), slots, application count, posted date. Status filter tabs. Create button.
- **PostingFormPage**: Form with all fields. Department dropdown, optional position dropdown, employment type select, rich text or textarea for description/requirements, salary range, show salary toggle, slots, close date. Zod validation.
- **PostingDetailPage**: Show posting info + table of its applications. Status change buttons (Open/Close/Filled).
- **ApplicationsListPage**: Table with columns: name, position, stage (Chip), applied date. Stage filter tabs. Click to detail.
- **ApplicationDetailPage**: Full application info, resume download link, timeline showing stage history. Action buttons: Advance / Reject. Interview scheduling dialog. Notes section with add form. If stage=hired, show "Convert to Employee" button that navigates to `/hr/employees/create?from_application={id}`.

Step-by-step instructions are intentionally lighter here — follow the existing ERP page patterns. The subagent should read `docs/PATTERNS.md`, an existing list page (e.g., `spa/src/pages/hr/succession-plans/index.tsx`), and an existing detail page for the correct patterns.

- [ ] **Step 1: Build RecruitmentDashboard** — stage counts + recent applications
- [ ] **Step 2: Build PostingsListPage** — table with filters
- [ ] **Step 3: Build PostingFormPage (create + edit)** — form with Zod validation
- [ ] **Step 4: Build PostingDetailPage** — posting info + application table + status actions
- [ ] **Step 5: Build ApplicationsListPage** — table with stage filter tabs
- [ ] **Step 6: Build ApplicationDetailPage** — full detail + pipeline actions + interviews + notes + conversion
- [ ] **Step 7: Verify pages render** — start dev server, login as HR, navigate through all pages
- [ ] **Step 8: Commit**

```bash
git add spa/src/pages/hr/recruitment/
git commit -m "feat(recruitment): add HR recruitment management pages"
```

---

### Task 9: Employee Conversion Integration + Final Wiring

**Files:**
- Modify: `spa/src/pages/hr/employees/create.tsx` (read `from_application` query param and pre-fill)
- Modify: `api/app/Modules/HR/Controllers/EmployeeController.php` (after create, check for application link and mark converted)

**Interfaces:**
- Consumes: `recruitmentApi.getConversionData()`, `RecruitmentService::markConverted()`, existing Employee create flow
- Produces: End-to-end hire conversion flow: hired applicant → pre-filled employee form → employee created → application marked converted → posting auto-fills if slots full

- [ ] **Step 1: Modify employee create page to read `from_application` query param**

In `spa/src/pages/hr/employees/create.tsx`:
- Read `from_application` from URL search params
- If present, call `recruitmentApi.getConversionData(from_application)` on mount
- Pre-fill the form with the returned data (first_name, last_name, email, phone, department_id, position_id)
- Show a banner: "Pre-filled from job application {application_number}"

- [ ] **Step 2: Modify `EmployeeController::store()` to link back to application**

After the employee is created, check for `from_application` in the request. If present:
- Decode the hashId to find the `JobApplication`
- Call `RecruitmentService::markConverted($application, $employee)`

Add to `StoreEmployeeRequest` rules:
```php
'from_application' => ['nullable', 'string'],
```

Add to `EmployeeController::store()` after `$employee = $this->service->create(...)`:
```php
if ($request->filled('from_application')) {
    $decoded = app('hashids')->decode($request->input('from_application'));
    if (!empty($decoded)) {
        $application = \App\Modules\HR\Models\JobApplication::find($decoded[0]);
        if ($application && $application->stage === \App\Modules\HR\Enums\ApplicationStage::Hired) {
            app(\App\Modules\HR\Services\RecruitmentService::class)->markConverted($application, $employee);
        }
    }
}
```

- [ ] **Step 3: Test the full conversion flow manually**

1. Create a job posting, set to Open
2. Submit a public application
3. Advance through stages to Hired
4. Click Convert to Employee → verify form pre-fills
5. Submit employee creation → verify application gets `converted_employee_id`

- [ ] **Step 4: Commit**

```bash
git add spa/src/pages/hr/employees/create.tsx api/app/Modules/HR/Controllers/EmployeeController.php api/app/Modules/HR/Requests/StoreEmployeeRequest.php
git commit -m "feat(recruitment): wire hire-to-employee conversion flow"
```

---

### Task 10: Final Verification + Cleanup

**Files:**
- Potentially modify: any files from prior tasks that fail tests or have issues

**Interfaces:**
- Consumes: Everything from Tasks 1–9
- Produces: Verified, working recruitment module

- [ ] **Step 1: Run full backend test suite**

Run: `cd api && php artisan test --filter='Recruitment|PublicRecruitment' -v`

Expected: All tests pass.

- [ ] **Step 2: Verify public careers pages**

1. Navigate to `/careers` — see open postings
2. Click a posting — see detail + application form
3. Submit application — get tracking code
4. Navigate to `/careers/track` — enter code — see status

- [ ] **Step 3: Verify HR recruitment pages**

1. Login as HR Officer
2. Navigate to `/hr/recruitment` — see dashboard
3. Create a job posting, set to Open
4. View applications list
5. Advance an application through stages
6. Schedule an interview, add notes
7. Mark as Hired, convert to employee

- [ ] **Step 4: Verify sidebar and landing footer**

1. Sidebar shows "Recruitment" under Human Resources (only for HR roles)
2. Landing page footer "Careers" link goes to `/careers`

- [ ] **Step 5: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix(recruitment): address final verification issues"
```
