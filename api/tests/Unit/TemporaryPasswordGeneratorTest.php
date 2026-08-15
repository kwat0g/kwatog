<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\TemporaryPasswordGenerator;
use Tests\TestCase;

class TemporaryPasswordGeneratorTest extends TestCase
{
    public function test_generated_temporary_password_contains_every_required_character_class(): void
    {
        $password = app(TemporaryPasswordGenerator::class)->generate();

        $this->assertGreaterThanOrEqual(8, strlen($password));
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $password);
    }
}
