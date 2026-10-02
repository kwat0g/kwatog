<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use Tests\TestCase;

final class PhpunitEnvironmentTest extends TestCase
{
    public function test_application_boots_in_testing_environment(): void
    {
        self::assertSame('testing', app()->environment());
    }
}
