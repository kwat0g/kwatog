<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhpunitDatabaseIsolationTest extends TestCase
{
    public function test_database_setting_has_safe_default_without_blocking_test_database_overrides(): void
    {
        $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
        $databaseSettings = [];

        $this->assertSame('tests/phpunit-bootstrap.php', (string) $configuration['bootstrap']);
        foreach ($configuration->php->children() as $setting) {
            if (in_array($setting->getName(), ['env', 'server'], true)
                && (string) $setting['name'] === 'DB_DATABASE') {
                $databaseSettings[] = $setting;
            }
        }

        $this->assertCount(2, $databaseSettings);
        foreach ($databaseSettings as $setting) {
            $this->assertSame('ogami_test', (string) $setting['value']);
            $this->assertNotSame('true', (string) $setting['force']);
        }
    }

    #[DataProvider('databaseNames')]
    public function test_bootstrap_accepts_only_test_database_names(string $requested, string $expected): void
    {
        $bootstrap = dirname(__DIR__).'/phpunit-bootstrap.php';
        $process = new Process([
            PHP_BINARY,
            '-r',
            'require '.var_export($bootstrap, true).'; echo getenv("DB_DATABASE");',
        ], dirname(__DIR__, 2), ['DB_DATABASE' => $requested]);
        $process->mustRun();

        $this->assertSame($expected, trim($process->getOutput()));
    }

    public static function databaseNames(): array
    {
        return [
            'default database' => ['ogami_test', 'ogami_test'],
            'explicit test database' => ['ogami_test_punch', 'ogami_test_punch'],
            'application database' => ['ogami', 'ogami_test'],
            'unrelated database' => ['other_database', 'ogami_test'],
        ];
    }
}
