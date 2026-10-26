<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_and_sidebar_preferences_are_saved_per_user_and_merged(): void
    {
        $user = User::factory()->create([
            'table_preferences' => [
                'inventory.items' => ['density' => 'compact', 'hiddenColumns' => ['supplier']],
            ],
        ]);

        $response = $this->actingAs($user)->patchJson('/api/v1/auth/user/preferences', [
            'sidebar_collapsed' => false,
            'sidebar_expand_all' => true,
            'table_preferences' => [
                'inventory.items' => ['density' => 'spacious'],
                'quality.calibration' => ['density' => 'compact'],
            ],
        ])->assertOk();

        $expected = [
            'inventory.items' => ['density' => 'spacious', 'hiddenColumns' => ['supplier']],
            'quality.calibration' => ['density' => 'compact'],
        ];

        $this->assertSame($expected, $response->json('data.table_preferences'));
        $this->assertSame($expected, $user->fresh()->table_preferences);
        $this->assertFalse($user->fresh()->sidebar_collapsed);
        $this->assertTrue($user->fresh()->sidebar_expand_all);

        $otherUser = User::factory()->create();
        $this->assertSame([], $otherUser->table_preferences ?? []);
    }

    public function test_table_preferences_reject_unknown_density_and_invalid_columns(): void
    {
        $this->actingAs(User::factory()->create())
            ->patchJson('/api/v1/auth/user/preferences', [
                'table_preferences' => [
                    'inventory.items' => ['density' => 'tiny'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['table_preferences']);
    }
}
