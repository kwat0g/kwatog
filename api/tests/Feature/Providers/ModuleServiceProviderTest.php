<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Providers\ModuleServiceProvider;
use PHPUnit\Framework\TestCase;

class ModuleServiceProviderTest extends TestCase
{
    public function test_registered_modules_and_route_bearing_module_directories_stay_in_sync(): void
    {
        $reflection = new \ReflectionClass(ModuleServiceProvider::class);
        $registered = $reflection->getConstant('MODULES');

        self::assertIsArray($registered);
        self::assertSame(
            count($registered),
            count(array_unique($registered)),
            'Module registry must not contain duplicate entries.',
        );

        $routeFiles = glob(dirname(__DIR__, 3).'/app/Modules/*/routes.php') ?: [];
        $routeBearingModules = array_map(
            static fn (string $path): string => basename(dirname($path)),
            $routeFiles,
        );

        sort($registered);
        sort($routeBearingModules);

        self::assertSame(
            $routeBearingModules,
            $registered,
            'Every route-bearing module must be registered, and every registered module must have routes.php.',
        );
    }
}
