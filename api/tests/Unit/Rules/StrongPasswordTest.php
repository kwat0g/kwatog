<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Common\Rules\StrongPassword;
use Tests\TestCase;

/**
 * Phase 2 Task 12 — covers the password policy: length 8, uppercase,
 * lowercase, digit, special. Lowercase was missing pre-fix; the regression
 * cases here are the real anchor of this task.
 */
class StrongPasswordTest extends TestCase
{
    /**
     * Run the rule and capture any failure message, since ValidationRule
     * uses a Closure-based fail() callback rather than a return value.
     */
    private function runRule(string $value): ?string
    {
        $rule = new StrongPassword();
        $message = null;
        $rule->validate('password', $value, function (string $msg) use (&$message): void {
            $message ??= $msg;
        });
        return $message;
    }

    public function test_passes_for_well_formed_password(): void
    {
        $this->assertNull($this->runRule('Password1!'));
    }

    public function test_passes_for_complex_password(): void
    {
        $this->assertNull($this->runRule('CorrectHorse-1!'));
    }

    public function test_rejects_password_shorter_than_eight(): void
    {
        $msg = $this->runRule('Pa1!');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('at least 8 characters', $msg);
    }

    public function test_rejects_password_missing_uppercase(): void
    {
        $msg = $this->runRule('password1!');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('uppercase', $msg);
    }

    public function test_rejects_all_uppercase_password(): void
    {
        // Regression: pre-fix this used to pass because lowercase wasn't required.
        $msg = $this->runRule('PASSWORD1!');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('lowercase', $msg);
    }

    public function test_rejects_password_missing_digit(): void
    {
        $msg = $this->runRule('Password!!');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('digit', $msg);
    }

    public function test_rejects_password_missing_special_character(): void
    {
        $msg = $this->runRule('Password11');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('special', $msg);
    }

    public function test_rejects_non_string_input(): void
    {
        $rule = new StrongPassword();
        $message = null;
        $rule->validate('password', 12345678, function (string $msg) use (&$message): void {
            $message ??= $msg;
        });
        $this->assertNotNull($message);
        $this->assertStringContainsString('at least 8 characters', $message);
    }
}
