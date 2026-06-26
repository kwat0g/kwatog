# Recruitment Module — Design Spec

> **Module:** HR / Recruitment (submodule)
> **Date:** 2026-06-27
> **Scope:** Pipeline-tracked job applications with public careers page

## Overview

Recruitment module for Philippine Ogami Corporation. External applicants apply via a public `/careers` page. HR Officer/Manager manage job postings and shepherd applications through a 5-stage pipeline. Hired applicants pre-fill the employee creation form.

Belongs to Chain 3 (Hire to Retire) — the very first step before "Hire."

## Data Model

### `job_postings`

| Column | Type | Constraints |
|---|---|---|
| id | bigint | PK, auto-increment |
| posting_number | varchar(20) | unique, generated (JP-YYYYMM-NNNN) |
| position_id | bigint nullable | FK → positions (optional link) |
| department_id | bigint | FK → departments |
| title | varchar(200) | required |
| description | text | required — job description |
| requirements | text | required — qualifications/skills |
| employment_type | enum | regular, probationary, contractual, project_based (reuse existing HR\EmploymentType) |
| salary_range_min | decimal(15,2) nullable | |
| salary_range_max | decimal(15,2) nullable | |
| show_salary | boolean | default false |
| status | enum | draft, open, closed, filled |
| slots | integer | default 1 — positions to fill |
| posted_at | timestamp nullable | set when status → open |
| closes_at | timestamp nullable | optional deadline |
| created_by | bigint | FK → users |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp nullable | soft delete |

**Traits:** HasHashId, HasAuditLog, SoftDeletes

**Sequence:** `JP-YYYYMM-NNNN` via `document_sequences` table.

### `job_applications`

| Column | Type | Constraints |
|---|---|---|
| id | bigint | PK, auto-increment |
| application_number | varchar(20) | unique, generated (JA-YYYYMM-NNNN) |
| job_posting_id | bigint | FK → job_postings |
| tracking_code | varchar(10) | unique, indexed — public tracking |
| first_name | varchar(100) | required |
| last_name | varchar(100) | required |
| email | varchar(255) | required |
| phone | varchar(30) | required |
| resume_path | varchar(500) | required — stored file path |
| resume_original_name | varchar(255) | original uploaded filename |
| cover_letter | text nullable | optional |
| stage | enum | new, screening, interview, offer, hired, rejected |
| rejected_at_stage | varchar(20) nullable | stage when rejected |
| rejection_reason | text nullable | internal reason |
| converted_employee_id | bigint nullable | FK → employees (set after hire conversion) |
| applied_at | timestamp | defaults to created_at |
| created_at | timestamp | |
| updated_at | timestamp | |

**Traits:** HasHashId

**Sequence:** `JA-YYYYMM-NNNN` via `document_sequences` table.

**No soft delete** — applications are permanent records.

### `application_interviews`

| Column | Type | Constraints |
|---|---|---|
| id | bigint | PK, auto-increment |
| job_application_id | bigint | FK → job_applications |
| scheduled_at | datetime | required |
| location | varchar(200) nullable | room/address/video link |
| interviewer_name | varchar(200) | required |
| notes | text nullable | filled after interview |
| outcome | enum nullable | passed, failed, pending |
| created_by | bigint | FK → users |
| created_at | timestamp | |
| updated_at | timestamp | |

### `application_notes`

| Column | Type | Constraints |
|---|---|---|
| id | bigint | PK, auto-increment |
| job_application_id | bigint | FK → job_applications |
| user_id | bigint | FK → users |
| body | text | required |
| created_at | timestamp | |
| updated_at | timestamp | |

## Enums

### `JobPostingStatus`
- `draft` — not visible publicly
- `open` — visible on /careers, accepting applications
- `closed` — no longer accepting, still visible as "closed"
- `filled` — all slots filled

### `ApplicationStage`
- `new` — just submitted
- `screening` — HR reviewing resume/qualifications
- `interview` — interview scheduled or completed
- `offer` — offer extended
- `hired` — accepted, ready for employee conversion
- `rejected` — rejected at any stage

### `EmploymentType` (reuse existing)

Existing enum at `App\Modules\HR\Enums\EmploymentType`:
- `regular`
- `probationary`
- `contractual`
- `project_based`

No new enum needed.

### `InterviewOutcome`
- `pending`
- `passed`
- `failed`

## Pipeline Flow

```
Applicant submits via /careers
         │
         ▼
    ┌─── NEW ───┐
    │            │──→ REJECTED (at any stage)
    ▼            │
 SCREENING ─────┤
    │            │
    ▼            │
 INTERVIEW ─────┤
    │            │
    ▼            │
   OFFER ───────┘
    │
    ▼
  HIRED → Pre-fill Employee creation
```

**Stage transition rules:**
- Forward only: new → screening → interview → offer → hired
- Reject from any stage (records `rejected_at_stage`)
- Cannot skip stages
- Cannot move backwards
- `hired` and `rejected` are terminal

## API Endpoints

### Public (no authentication)

Rate limit: 10/min on POST, 30/min on GET (per IP).

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/public/job-postings` | List open postings (paginated, filter by department) |
| GET | `/api/v1/public/job-postings/{hashId}` | Single posting detail |
| POST | `/api/v1/public/job-postings/{hashId}/apply` | Submit application (multipart) |
| GET | `/api/v1/public/applications/track/{tracking_code}` | Application status for applicant |

**Public listing response shape:**
```json
{
  "data": [{
    "id": "yR3kLm",
    "posting_number": "JP-202606-0001",
    "title": "Injection Molding Operator",
    "department": { "id": "xK9pQ2", "name": "Production" },
    "employment_type": "full_time",
    "salary_range": { "min": "18000.00", "max": "22000.00" },  // null if show_salary=false
    "posted_at": "2026-06-15T08:00:00Z",
    "closes_at": "2026-07-15T23:59:59Z"
  }]
}
```

**Apply request (multipart/form-data):**
```
first_name: string (required, max 100)
last_name: string (required, max 100)
email: string (required, valid email)
phone: string (required, max 30)
resume: file (required, pdf/doc/docx, max 5MB)
cover_letter: string (optional, max 5000 chars)
```

**Apply response:**
```json
{
  "tracking_code": "RCT-A7K2M9",
  "message": "Application submitted successfully."
}
```

**Track response:**
```json
{
  "tracking_code": "RCT-A7K2M9",
  "position": "Injection Molding Operator",
  "applied_at": "2026-06-20T14:30:00Z",
  "status": "Interview Scheduled",
  "interview": {
    "scheduled_at": "2026-06-28T09:00:00Z",
    "location": "HR Office, 2nd Floor"
  }
}
```

Status labels for applicant (friendly, no internal jargon):
- `new` / `screening` → "Application Received"
- `interview` → "Interview Scheduled" (if interview exists) or "Under Review"
- `offer` → "Offer Extended"
- `hired` → "Hired"
- `rejected` → "Not Selected"

### HR-Facing (authenticated, `hr.recruitment.*` permissions)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/recruitment/postings` | List all postings (filter by status) |
| POST | `/api/v1/recruitment/postings` | Create posting |
| GET | `/api/v1/recruitment/postings/{id}` | Posting detail + application counts |
| PUT | `/api/v1/recruitment/postings/{id}` | Update posting |
| DELETE | `/api/v1/recruitment/postings/{id}` | Soft-delete posting |
| PATCH | `/api/v1/recruitment/postings/{id}/status` | Change posting status (open/close/fill) |
| GET | `/api/v1/recruitment/applications` | List all applications (filter stage, posting) |
| GET | `/api/v1/recruitment/applications/{id}` | Application detail (timeline) |
| PATCH | `/api/v1/recruitment/applications/{id}/stage` | Advance or reject |
| POST | `/api/v1/recruitment/applications/{id}/interviews` | Schedule interview |
| PATCH | `/api/v1/recruitment/interviews/{id}` | Update interview (notes/outcome) |
| POST | `/api/v1/recruitment/applications/{id}/notes` | Add internal note |
| GET | `/api/v1/recruitment/applications/{id}/resume` | Download resume file |
| GET | `/api/v1/recruitment/applications/{id}/convert` | Get pre-filled employee data |

## Permissions

| Permission | Description | Roles |
|---|---|---|
| `hr.recruitment.view` | View postings + applications | hr_officer, hr_manager |
| `hr.recruitment.manage` | Create/edit/close postings | hr_officer, hr_manager |
| `hr.recruitment.applications` | Move stages, add notes, schedule interviews | hr_officer, hr_manager |
| `hr.recruitment.hire` | Mark hired, trigger employee conversion | hr_manager |

`system_admin` inherits all.

## SPA Routes

### Public (no auth guard)

| Path | Page | Description |
|---|---|---|
| `/careers` | CareersPage | List of open positions |
| `/careers/:id` | JobPostingDetailPage | Full job description + inline apply form |
| `/careers/track` | ApplicationTrackPage | Enter tracking code → see status |

### HR-Facing (auth + module + permission guards)

| Path | Page | Description |
|---|---|---|
| `/hr/recruitment` | RecruitmentDashboard | Stage counts, recent applications |
| `/hr/recruitment/postings` | PostingsListPage | All postings with status filter |
| `/hr/recruitment/postings/create` | PostingFormPage | Create job posting |
| `/hr/recruitment/postings/:id` | PostingDetailPage | Detail + application list |
| `/hr/recruitment/postings/:id/edit` | PostingFormPage | Edit job posting |
| `/hr/recruitment/applications` | ApplicationsListPage | All applications, filter by stage/posting |
| `/hr/recruitment/applications/:id` | ApplicationDetailPage | Full detail: timeline, interviews, notes, stage actions |

## File Structure (new files)

### Backend

```
api/app/Modules/HR/
├── Enums/
│   ├── JobPostingStatus.php
│   ├── ApplicationStage.php
│   └── InterviewOutcome.php
├── Models/
│   ├── JobPosting.php
│   ├── JobApplication.php
│   ├── ApplicationInterview.php
│   └── ApplicationNote.php
├── Services/
│   └── RecruitmentService.php
├── Controllers/
│   ├── RecruitmentPostingController.php      (HR-facing)
│   ├── RecruitmentApplicationController.php  (HR-facing)
│   └── PublicRecruitmentController.php       (public-facing)
├── Requests/
│   ├── StoreJobPostingRequest.php
│   ├── UpdateJobPostingRequest.php
│   ├── PublicApplicationRequest.php
│   ├── AdvanceApplicationRequest.php
│   └── StoreInterviewRequest.php
├── Resources/
│   ├── JobPostingResource.php
│   ├── PublicJobPostingResource.php
│   ├── JobApplicationResource.php
│   ├── ApplicationInterviewResource.php
│   └── ApplicationTrackingResource.php
└── Mail/
    ├── ApplicationReceivedMail.php
    └── InterviewScheduledMail.php
```

### Frontend

```
spa/src/
├── pages/
│   ├── careers/
│   │   ├── index.tsx                    (CareersPage — job listing)
│   │   ├── detail.tsx                   (JobPostingDetailPage + inline apply form)
│   │   └── track.tsx                    (ApplicationTrackPage)
│   └── hr/
│       └── recruitment/
│           ├── index.tsx                (RecruitmentDashboard)
│           ├── postings/
│           │   ├── index.tsx            (PostingsListPage)
│           │   ├── create.tsx           (PostingFormPage)
│           │   ├── detail.tsx           (PostingDetailPage)
│           │   └── edit.tsx             (PostingFormPage reuse)
│           └── applications/
│               ├── index.tsx            (ApplicationsListPage)
│               └── detail.tsx           (ApplicationDetailPage)
├── api/
│   ├── recruitment.ts                   (HR API functions)
│   └── public-recruitment.ts            (public API functions)
├── types/
│   └── recruitment.ts                   (TypeScript interfaces)
└── routes/
    └── careersRoutes.tsx                 (public /careers routes)
    (+ update hrRoutes.tsx for /hr/recruitment/*)
```

### Migrations

```
0248_create_job_postings_table.php
0249_create_job_applications_table.php
0250_create_application_interviews_table.php
0251_create_application_notes_table.php
```

### Seeders

- Add `JP` and `JA` to `document_sequences` seeder
- Add `hr.recruitment.*` permissions to `RolePermissionSeeder`
- Add `recruitment` feature toggle to settings seeder

## Email

### ApplicationReceivedMail

Sent to applicant on successful submission. Contains:
- Applicant name
- Position title
- Tracking code
- Link to `/careers/track`
- "Thank you for your interest in Philippine Ogami Corporation"

### InterviewScheduledMail

Sent to applicant when interview is scheduled. Contains:
- Applicant name
- Position title
- Interview date/time
- Location
- Interviewer name
- Company address reminder

Both use Laravel Mailable with Blade templates. No queue (low volume, immediate delivery expected).

## Resume File Storage

- Disk: `local` (not public)
- Path: `recruitment/resumes/{Y}/{m}/{uuid}.{ext}`
- Validation: `mimes:pdf,doc,docx`, `max:5120` (5MB)
- Original filename preserved in `resume_original_name` column
- Download via `RecruitmentApplicationController@downloadResume` (auth required, permission check)

## Tracking Code Generation

Format: `RCT-{6 alphanumeric}` (e.g., `RCT-A7K2M9`)

Generated in `RecruitmentService::generateTrackingCode()`:
- 6 uppercase alphanumeric characters (no ambiguous: 0/O, 1/I/L removed)
- Collision check: retry up to 5 times if duplicate found
- Indexed column for fast lookup

## Hire Conversion Flow

1. HR clicks "Convert to Employee" on hired application
2. Frontend calls `GET /api/v1/recruitment/applications/{id}/convert`
3. API returns pre-filled employee data:
   ```json
   {
     "first_name": "Juan",
     "last_name": "Dela Cruz",
     "email": "juan@example.com",
     "phone": "09171234567",
     "department_id": "xK9pQ2",
     "position_id": "yR3kLm"
   }
   ```
4. Frontend navigates to `/hr/employees/create?from_application={hashId}`
5. Employee creation page reads query param, calls convert endpoint, pre-fills form
6. HR fills remaining fields (date_hired, pay_type, salary, etc.) and submits
7. After employee created, system updates `job_applications.converted_employee_id`
8. If all slots filled, posting status auto-transitions to `filled`

## Careers Page Design

Follows landing page aesthetic (monochrome warm canvas, Bricolage Grotesque display font) — NOT the ERP design system. Shares the `LandingNav` and `LandingFooter` components.

- **CareersPage:** Grid of job cards (title, department, type, posted date). Filter by department dropdown. Clean, professional.
- **JobPostingDetailPage:** Full description, requirements, employment type, salary (if shown), "Apply Now" CTA.
- **ApplicationFormPage:** Clean form — name fields, email, phone, file upload, cover letter textarea. Submit → success screen with tracking code.
- **ApplicationTrackPage:** Single input for tracking code. Shows timeline/stepper of current stage.

## Dashboard (HR)

`/hr/recruitment` shows:
- Stage breakdown cards (count per stage across all open postings)
- Recent applications (last 10)
- Open postings count
- Quick link to create new posting

## Sidebar Addition

Add under HR section:
```
HR
├── ...existing items...
├── Recruitment          (/hr/recruitment)
│   ├── Job Postings     (/hr/recruitment/postings)
│   └── Applications     (/hr/recruitment/applications)
```

## Notifications

- `NotificationService::send()` to HR users with `hr.recruitment.applications` permission when new application submitted
- Type: `recruitment.new_application`
- Data: `{ posting_title, applicant_name, application_id }`
