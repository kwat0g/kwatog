<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\Export\ExportColumnRegistry;
use App\Modules\Auth\Models\User;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ExportColumnRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Don't pollute other tests with our scratch namespace; tearDown
        // resets only what we registered.
    }

    public function test_register_and_lookup(): void
    {
        ExportColumnRegistry::register('test.scratch', [
            'a' => ['label' => 'A', 'default' => true],
            'b' => ['label' => 'B'],
        ]);

        $this->assertTrue(ExportColumnRegistry::has('test.scratch'));
        $this->assertSame(['a'], ExportColumnRegistry::defaultsFor('test.scratch'));
        $this->assertCount(2, ExportColumnRegistry::for('test.scratch'));
    }

    public function test_missing_label_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExportColumnRegistry::register('test.broken', [
            'a' => ['default' => true],
        ]);
    }

    public function test_unknown_columns_are_rejected_instead_of_humanized(): void
    {
        ExportColumnRegistry::register('test.strict', [
            'safe' => ['label' => 'Safe', 'resolver' => static fn (): string => 'ok'],
        ]);

        $this->expectException(ValidationException::class);
        ExportColumnRegistry::validateColumns('test.strict', ['tin']);
    }

    public function test_permission_bearing_columns_require_the_explicit_capability(): void
    {
        ExportColumnRegistry::register('test.sensitive', [
            'salary' => [
                'label' => 'Salary',
                'permission' => 'test.view_sensitive',
                'resolver' => static fn (): string => 'secret',
            ],
        ]);
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('can')->once()->with('test.view_sensitive')->andReturnFalse();

        $this->expectException(ValidationException::class);
        ExportColumnRegistry::validateColumns('test.sensitive', ['salary'], $actor);
    }
}
