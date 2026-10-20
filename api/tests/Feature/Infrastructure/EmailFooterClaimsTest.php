<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Mail\EmailIntegrationTestMail;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmailFooterClaimsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_email_shell_uses_identity_without_unverified_claims(): void
    {
        $this->seed(SettingsSeeder::class);

        $html = (new EmailIntegrationTestMail)->render();

        $this->assertStringContainsString('Philippine Ogami Corporation', $html);
        $this->assertStringContainsString('FCIE Complex', $html);
        $this->assertStringContainsString('info@ogami.test', $html);
        $this->assertStringContainsString('This is an automated message.', $html);
        $this->assertStringNotContainsString('IATF 16949', $html);
        $this->assertStringNotContainsString('VAT Registered', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertStringNotContainsString('Please do not reply', $html);
    }
}
