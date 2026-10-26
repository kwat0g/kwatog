<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Enums\PermissionOverrideType;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Admin\Services\UserPermissionOverrideService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionOverrideNotificationCopyTest extends TestCase
{
    use DatabaseTruncation;

    public function test_affected_user_gets_clear_no_link_copy_for_each_override_change(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->userWithRole('system_admin');
        $target = $this->userWithRole('employee');
        $service = app(UserPermissionOverrideService::class);

        $service->set($target, $admin, 'hr.employees.view', PermissionOverrideType::Grant, 'Temporary access.');
        $this->assertNotice($target, 1, 'Access rule added', 'A rule to allow access to "View Employees" was added. If this was unexpected, contact your system administrator.');

        $this->travel(1)->seconds();
        $service->set($target, $admin, 'hr.employees.view', PermissionOverrideType::Revoke, 'Access removed.');
        $this->assertNotice($target, 2, 'Access rule updated', 'Your override for "View Employees" changed from allowing access to blocking access. If this was unexpected, contact your system administrator.');

        $override = UserPermissionOverride::query()->where('user_id', $target->id)->firstOrFail();
        $this->travel(1)->seconds();
        $service->remove($override, $admin);
        $this->assertNotice($target, 3, 'Access override removed', 'The override for "View Employees" was removed. Your role\'s default access now applies. If this was unexpected, contact your system administrator.');

        $this->travel(1)->seconds();
        $service->restore($target, UserPermissionOverride::withTrashed()->findOrFail($override->id), $admin);
        $this->assertNotice($target, 4, 'Access rule restored', 'Your previous rule to block access to "View Employees" is active again. If this was unexpected, contact your system administrator.');

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $admin->id)->where('type', 'permission.override')->count());
    }

    private function assertNotice(User $user, int $expectedCount, string $title, string $message): void
    {
        $rows = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', 'permission.override')
            ->orderBy('created_at')
            ->get();

        $this->assertCount($expectedCount, $rows);
        $data = json_decode((string) $rows->last()->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($title, $data['title']);
        $this->assertSame($message, $data['message']);
        $this->assertArrayNotHasKey('link_to', $data);
        $this->assertStringNotContainsString('hr.employees.view', $data['message']);
        $this->assertStringNotContainsString('"grant"', $data['message']);
        $this->assertStringNotContainsString('"revoke"', $data['message']);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }
}
