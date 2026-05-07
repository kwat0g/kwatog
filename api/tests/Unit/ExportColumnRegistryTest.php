<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\Export\ExportColumnRegistry;
use InvalidArgumentException;
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
}
