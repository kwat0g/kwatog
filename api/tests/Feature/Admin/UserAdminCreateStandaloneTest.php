<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Services\UserAdminService;
use App\Modules\Admin\Support\CreatedUser;
use App\Modules\Auth\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAdminCreateStandaloneTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_created_user_dto_with_user_and_temp_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');

        $result = $svc->createStandalone([
            'name'    => 'CLI Caller',
            'email'   => 'cli-caller@t.test',
            'role_id' => $roleId,
        ]);

        $this->assertInstanceOf(CreatedUser::class, $result);
        $this->assertSame('cli-caller@t.test', $result->user->email);
        $this->assertNotEmpty($result->tempPassword);
        $this->assertGreaterThanOrEqual(8, mb_strlen($result->tempPassword));
    }

    public function test_works_outside_http_context(): void
    {
        // Simulate Artisan/queued-job: createStandalone must NOT depend on
        // request()->attributes to return the temp password.
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');

        $result = $svc->createStandalone([
            'name'    => 'Queued Caller',
            'email'   => 'queued-caller@t.test',
            'role_id' => $roleId,
            'temp_password' => 'KnownTempPwd1!',
        ]);

        $this->assertSame('KnownTempPwd1!', $result->tempPassword);
    }
}
