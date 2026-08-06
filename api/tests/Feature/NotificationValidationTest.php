<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Input validation on the notification endpoints.
 *
 * `per_page` previously reached paginate() unvalidated — the service clamped
 * only the ceiling with min(), so a negative value became a negative SQL LIMIT
 * and a 500. Preference writes accepted any type string and any array size,
 * producing rows no page renders and no sender reads.
 */
class NotificationValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function createNotification(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(array_merge([
            'id'              => $id,
            'type'            => 'test.type',
            'notifiable_type' => User::class,
            'notifiable_id'   => $this->user->id,
            'data'            => json_encode(['title' => 'Test', 'message' => 'Msg']),
            'read_at'         => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));

        return $id;
    }

    /* ─── Pagination bounds ────────────────────────────────────────────── */

    public function test_negative_per_page_is_rejected_not_fatal(): void
    {
        $this->createNotification();

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?per_page=-5')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_zero_per_page_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_per_page_above_the_ceiling_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?per_page=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_valid_per_page_is_honoured(): void
    {
        foreach (range(1, 5) as $i) {
            $this->createNotification();
        }

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_omitted_per_page_defaults_without_error(): void
    {
        $this->createNotification();

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    /* ─── Preference writes ────────────────────────────────────────────── */

    public function test_unknown_notification_type_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['notification_type' => 'totally.made.up', 'channel' => 'in_app', 'enabled' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferences.0.notification_type');
    }

    public function test_catalog_type_is_accepted(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['notification_type' => 'leave.approved', 'channel' => 'in_app', 'enabled' => false],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id'           => $this->user->id,
            'notification_type' => 'leave.approved',
            'channel'           => 'in_app',
            'enabled'           => false,
        ]);
    }

    public function test_digest_channel_requires_the_global_type(): void
    {
        // A per-type digest row is never read by NotificationDigestService, so
        // storing one would be a switch that silently does nothing.
        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['notification_type' => 'leave.approved', 'channel' => 'digest', 'enabled' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferences.0.channel');
    }

    public function test_global_type_is_rejected_for_non_digest_channels(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['notification_type' => '*', 'channel' => 'in_app', 'enabled' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferences.0.notification_type');
    }

    public function test_global_digest_opt_in_still_works(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['notification_type' => '*', 'channel' => 'digest', 'enabled' => true],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id'           => $this->user->id,
            'notification_type' => '*',
            'channel'           => 'digest',
            'enabled'           => true,
        ]);
    }

    public function test_oversized_preference_batch_is_rejected(): void
    {
        $preferences = array_fill(0, 250, [
            'notification_type' => 'leave.approved',
            'channel'           => 'in_app',
            'enabled'           => true,
        ]);

        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', ['preferences' => $preferences])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferences');
    }

    public function test_column_wide_toggle_still_fits_under_the_cap(): void
    {
        // The preferences page "apply to every event" control sends one row per
        // catalog type; the cap must not break that legitimate request.
        $catalog = $this->actingAs($this->user)
            ->getJson('/api/v1/notification-preferences/options')
            ->assertOk()
            ->json('data.groups');

        $preferences = [];
        foreach ($catalog as $group) {
            foreach ($group['types'] as $type) {
                $preferences[] = [
                    'notification_type' => $type['key'],
                    'channel'           => 'in_app',
                    'enabled'           => false,
                ];
            }
        }

        $this->assertGreaterThan(30, count($preferences), 'Catalog should be substantial.');

        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', ['preferences' => $preferences])
            ->assertOk();
    }

    public function test_invalid_channel_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['notification_type' => 'leave.approved', 'channel' => 'carrier_pigeon', 'enabled' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferences.0.channel');
    }

    /* ─── Scoping ──────────────────────────────────────────────────────── */

    public function test_index_never_returns_another_users_notifications(): void
    {
        $other = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $this->createNotification();
        $this->createNotification(['notifiable_id' => $other->id]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    }
}
