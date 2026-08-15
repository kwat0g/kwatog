<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ProfileUpdateStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * P01-01 shape on the HR self-service profile-change flow: approve()/reject()
 * ran their status guards (`abort_unless`) on the *passed* model outside the
 * transaction. A stale reject landing after a concurrent approve flips an
 * already-approved request (whose employee changes — incl. bank details — were
 * already applied) back to Rejected.
 */
class ProfileUpdateStaleRejectRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function hrUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
    }

    public function test_stale_reject_cannot_flip_an_approved_request(): void
    {
        $svc = app(ProfileUpdateRequestService::class);
        $requester = $this->hrUser();
        $reviewer = $this->hrUser();
        $employee = Employee::factory()->create();

        $request = $svc->submit($employee, $requester, ['mobile_number' => '09170000000']);

        // Approver and rejecter each fetched the row while it was pending.
        $approveSnapshot = ProfileUpdateRequest::find($request->id);
        $rejectSnapshot = ProfileUpdateRequest::find($request->id);

        // Approver commits first — changes applied, request approved.
        $svc->approve($approveSnapshot, $reviewer);
        $this->assertSame(ProfileUpdateStatus::Approved->value, $request->refresh()->status);
        $this->assertSame('09170000000', $employee->refresh()->mobile_number);

        // Concurrent stale rejecter still sees `pending` in memory.
        try {
            $svc->reject($rejectSnapshot, $reviewer, 'denied');
            $this->fail('A stale reject must not flip an approved request.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(ProfileUpdateStatus::Approved->value, $request->refresh()->status);
    }
}
