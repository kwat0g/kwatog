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
