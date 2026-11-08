<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SkillChanged;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Services\SkillService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SkillRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.hr', true, 'modules');
    }

    public function test_skill_catalog_mutations_emit_content_free_after_commit_signals(): void
    {
        $actor = $this->userWithPermissions('hr.trainings.manage');
        $this->actingAs($actor);

        $skill = app(SkillService::class)->create([
            'name' => 'Injection press changeover',
            'category' => 'Production',
            'description' => 'Authorized qualification description',
        ]);

        $message = DB::table('event_outbox')->where('event_type', SkillChanged::class)->sole();
        $event = app(OutboxEventCodec::class)->decode(
            $message->event_type,
            json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(SkillChanged::class, $event);
        self::assertSame($skill->hash_id, $event->hashId);
        self::assertSame('created', $event->action);
        self::assertSame($skill->updated_at?->format('Y-m-d\\TH:i:s.uP'), $event->version);
        self::assertSame([
            'private-entity.skill.visible',
            'private-entity.employee_skill.visible',
            'private-skill.'.$skill->hash_id,
        ], array_map(static fn ($channel) => $channel->name, $event->broadcastOn()));
        self::assertArrayNotHasKey('entity_id', $event->broadcastWith());
        self::assertStringNotContainsString('Authorized qualification description', json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR));

        DB::table('event_outbox')->where('event_type', SkillChanged::class)->delete();
        app(SkillService::class)->update($skill, ['name' => 'Injection press changeover v2']);
        $updated = DB::table('event_outbox')->where('event_type', SkillChanged::class)->sole();
        self::assertSame('updated', json_decode((string) $updated->payload, true, flags: JSON_THROW_ON_ERROR)['action']);

        DB::table('event_outbox')->where('event_type', SkillChanged::class)->delete();
        app(SkillService::class)->deactivate($skill->refresh());
        $deactivated = DB::table('event_outbox')->where('event_type', SkillChanged::class)->sole();
        self::assertSame('updated', json_decode((string) $deactivated->payload, true, flags: JSON_THROW_ON_ERROR)['action']);
    }

    public function test_catalog_channels_require_the_hr_read_permission_and_existing_skill(): void
    {
        $skill = Skill::query()->create(['name' => 'Mold safety inspection']);
        $reader = $this->userWithPermissions('hr.trainings.view');
        $manager = $this->userWithPermissions('hr.trainings.manage');
        $outsider = $this->userWithPermissions();

        $this->authorizeChannel($reader, 'entity.skill.visible')->assertOk();
        $this->authorizeChannel($reader, 'skill.'.$skill->hash_id)->assertOk();
        $this->authorizeChannel($manager, 'entity.skill.visible')->assertForbidden();
        $this->authorizeChannel($outsider, 'entity.skill.visible')->assertForbidden();
        $this->authorizeChannel($reader, 'skill.not-a-real-hash')->assertForbidden();
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $suffix = substr(uniqid(), -6);
        $role = Role::query()->create([
            'name' => 'Skill catalog reader '.$suffix,
            'slug' => 'skill_catalog_reader_'.$suffix,
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChannel(User $user, string $channel)
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-'.$channel,
        ]);
    }
}
