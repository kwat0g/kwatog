<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Services;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RealtimeMutationCoverageContractTest extends TestCase
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function test_application_broadcast_events_use_after_commit_and_the_dedicated_queue(): void
    {
        $applicationFiles = File::allFiles(app_path());
        $broadcastEvents = array_values(array_filter(
            $applicationFiles,
            static function ($file): bool {
                $source = File::get($file->getPathname());

                return preg_match('/\bclass\s+\w+[^\{]*\bimplements\b[^\{]*\bShouldBroadcast(?:Now)?\b/s', $source) === 1;
            },
        ));

        $this->assertNotEmpty($broadcastEvents, 'The application broadcast-event inventory should not silently disappear.');

        foreach ($broadcastEvents as $file) {
            $source = File::get($file->getPathname());
            $path = $file->getRelativePathname();

            $this->assertStringContainsString(
                'ShouldDispatchAfterCommit',
                $source,
                $path.' must defer dispatch until the database transaction commits.',
            );
            $this->assertStringContainsString(
                'BroadcastsOnDedicatedQueue',
                $source,
                $path.' must use the supervised broadcasts queue.',
            );
        }
    }

    public function test_realtime_observers_are_registered_and_runtime_writers_have_a_producer_disposition(): void
    {
        $applicationFiles = File::allFiles(app_path());
        $providerSource = implode("\n", array_map(
            static fn ($file): string => File::get($file->getPathname()),
            array_filter(
                $applicationFiles,
                static fn ($file): bool => str_ends_with($file->getFilename(), 'ServiceProvider.php'),
            ),
        ));

        $observerFiles = array_values(array_filter(
            $applicationFiles,
            static fn ($file): bool => str_ends_with($file->getFilename(), 'RealtimeObserver.php'),
        ));
        $this->assertNotEmpty($observerFiles, 'The realtime observer inventory should not silently disappear.');

        foreach ($observerFiles as $file) {
            $source = File::get($file->getPathname());
            $this->assertMatchesRegularExpression('/\bclass\s+(\w+)/', $source, $file->getRelativePathname());
            preg_match('/\bclass\s+(\w+)/', $source, $matches);
            $observer = $matches[1];
            $this->assertMatchesRegularExpression(
                '/\bfunction\s+(?:created|updated|saved|deleted|restored|forceDeleted)\s*\(/',
                $source,
                $file->getRelativePathname().' must listen to an Eloquent lifecycle hook.',
            );
            $this->assertMatchesRegularExpression(
                '/\b(?:stage|dispatch|broadcast|invalidate|refresh|emit|changed|change|touch|recordChange|publish)[A-Za-z0-9_]*\s*\(/',
                $source,
                $file->getRelativePathname().' must call a realtime or cache invalidation path.',
            );
            $pattern = '/::observe\s*\(\s*'.preg_quote($observer, '/').'::class\s*\)/';

            $this->assertMatchesRegularExpression(
                $pattern,
                $providerSource,
                $file->getRelativePathname().' must be attached to a model by an application service provider.',
            );
        }

        $repositoryRoot = getenv('REALTIME_REPO_ROOT');
        if (! is_string($repositoryRoot) || trim($repositoryRoot) === '') {
            $repositoryRoot = dirname(base_path());
        }

        $ledgerPath = rtrim($repositoryRoot, DIRECTORY_SEPARATOR).'/docs/realtime/00-direct-status-write-paths.csv';
        $handle = fopen($ledgerPath, 'rb');
        $this->assertNotFalse($handle, 'The direct status-writer inventory is required.');

        try {
            $headers = fgetcsv($handle);
            $this->assertIsArray($headers);
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            $rows = 0;

            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null]) {
                    continue;
                }

                $this->assertCount(count($headers), $values, 'Every status-writer row must preserve the inventory schema.');
                $row = array_combine($headers, $values);
                $this->assertIsArray($row);
                $this->assertNotSame('', trim((string) $row['source_writer']), 'Every writer needs a source reference.');
                $this->assertNotSame('', trim((string) $row['consumer_scope']), 'Every writer needs an audience or explicit no-consumer reason.');

                $producer = trim((string) $row['realtime_producer']);
                $this->assertNotSame('', $producer, 'Every writer needs a producer or an explicit no-event disposition.');

                $producerReference = trim((string) ($row['producer_reference'] ?? ''));
                $this->assertNotSame(
                    '',
                    $producerReference,
                    $row['module'].' '.$row['entity_type'].' '.$row['source_writer'].' needs a machine-readable producer reference.',
                );

                if (str_starts_with($producerReference, 'none:')) {
                    $setupOnly = in_array($row['mutation_kind'], ['migration/backfill', 'legacy_backfill', 'fixture initialization'], true)
                        || (str_contains(strtolower($row['source_writer']), 'seeder')
                            && str_contains(strtolower($producer), 'bootstrap/demo fixture'));
                    $scope = strtolower((string) $row['consumer_scope']);
                    $noSharedConsumer = str_contains($scope, 'no subscriber')
                        || str_contains($scope, 'no cross-user app screen')
                        || str_contains($scope, 'no supported runtime reader');

                    $this->assertTrue(
                        $setupOnly || $noSharedConsumer,
                        $row['module'].' '.$row['entity_type'].' '.$row['source_writer'].' has an unjustified no-event disposition.',
                    );
                } else {
                    $this->assertMatchesRegularExpression(
                        '/^(event|observer|service|helper|direct-service):App\\\\[A-Za-z0-9_\\\\]+$/',
                        $producerReference,
                        'Producer references must identify an application event, observer, service or helper class.',
                    );
                    [, $class] = explode(':', $producerReference, 2);
                    $this->assertTrue(
                        class_exists($class),
                        $row['module'].' '.$row['entity_type'].' references missing producer class '.$class.'.',
                    );
                }

                if (stripos($producer, 'No runtime event path') !== false) {
                    $setupOnly = in_array($row['mutation_kind'], ['migration/backfill', 'legacy_backfill', 'fixture initialization'], true)
                        || (str_contains(strtolower($row['source_writer']), 'seeder')
                            && str_contains(strtolower($producer), 'bootstrap/demo fixture'));
                    $scope = strtolower((string) $row['consumer_scope']);
                    $noSharedConsumer = str_contains($scope, 'no subscriber')
                        || str_contains($scope, 'no cross-user app screen')
                        || str_contains($scope, 'no supported runtime reader');

                    $this->assertTrue(
                        $setupOnly || $noSharedConsumer,
                        $row['module'].' '.$row['entity_type'].' '.$row['source_writer'].' has a runtime consumer but no realtime event path.',
                    );
                }

                $rows++;
            }
        } finally {
            fclose($handle);
        }

        $this->assertGreaterThan(0, $rows, 'The direct status-writer inventory should contain runtime mutation paths.');
    }

    public function test_direct_service_producer_rows_reach_the_service_from_each_writer(): void
    {
        $repositoryRoot = getenv('REALTIME_REPO_ROOT');
        if (! is_string($repositoryRoot) || trim($repositoryRoot) === '') {
            $repositoryRoot = dirname(base_path());
        }

        $rows = $this->readCsvRows($repositoryRoot.'/docs/realtime/00-direct-status-write-paths.csv');
        $directRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with($row['producer_reference'] ?? '', 'direct-service:'),
        ));
        $this->assertNotEmpty($directRows, 'The inventory must identify producer paths that are directly checked at source level.');

        $classesByShortName = $this->applicationClassesByShortName();

        foreach ($directRows as $row) {
            [, $producerClass] = explode(':', $row['producer_reference'], 2);
            preg_match_all(
                '/\b([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(|\b)/',
                $row['source_writer'],
                $writerMethods,
                PREG_SET_ORDER,
            );
            $this->assertNotEmpty(
                $writerMethods,
                $row['source_writer'].' must identify at least one executable writer method.',
            );

            foreach ($writerMethods as [, $writerShortName, $writerMethod]) {
                $writerClasses = $classesByShortName[$writerShortName] ?? [];
                $this->assertCount(
                    1,
                    $writerClasses,
                    $row['source_writer'].' must resolve to one application writer class.',
                );
                $visited = [];
                $this->assertTrue(
                    $this->writerMethodReachesService($writerClasses[0], $writerMethod, $producerClass, $visited),
                    $row['module'].' '.$row['entity_type'].' '.$row['source_writer'].' does not reach '.$producerClass
                        .' (checked '.implode(', ', array_keys($visited)).').',
                );
            }
        }
    }

    public function test_a_service_class_constant_alone_does_not_prove_a_producer_call(): void
    {
        $method = new \ReflectionMethod($this, 'methodReferencesServiceClass');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(
            $this,
            'ChainBroadcaster::class;',
            'use App\\Common\\Services\\ChainBroadcaster;',
            \App\Common\Services\ChainBroadcaster::class,
        ));
    }

    public function test_a_resolved_service_method_call_proves_a_producer_invocation(): void
    {
        $method = new \ReflectionMethod($this, 'methodReferencesServiceClass');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $this,
            'app(ChainBroadcaster::class)->refreshPurchaseOrder($purchaseOrder);',
            'use App\\Common\\Services\\ChainBroadcaster;',
            \App\Common\Services\ChainBroadcaster::class,
        ));
    }

    public function test_runtime_mutation_routes_are_inventoried_with_matrix_evidence(): void
    {
        $repositoryRoot = getenv('REALTIME_REPO_ROOT');
        if (! is_string($repositoryRoot) || trim($repositoryRoot) === '') {
            $repositoryRoot = dirname(base_path());
        }

        $routeInventory = $this->readCsvRows($repositoryRoot.'/docs/realtime/00-mutation-endpoints.csv');
        $matrix = $this->readCsvRows($repositoryRoot.'/docs/realtime/01-stale-risk-matrix.csv');
        $this->assertNotEmpty($routeInventory, 'The runtime mutation route inventory is required.');
        $this->assertNotEmpty($matrix, 'The stale-risk matrix is required.');

        $inventoryByKey = [];
        foreach ($routeInventory as $row) {
            if (! in_array($row['methods'], [...self::WRITE_METHODS, 'GET'], true)) {
                continue;
            }

            $inventoryByKey[$this->routeKey($row['methods'], $row['uri'], $row['controller_action'])] = $row;
        }

        $matrixByKey = [];
        foreach ($matrix as $row) {
            if (! in_array($row['methods'], [...self::WRITE_METHODS, 'GET'], true)) {
                continue;
            }

            $matrixByKey[$this->routeKey($row['methods'], $row['uri'], $row['controller_action'])] = $row;
        }

        $liveWriteRoutes = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach (array_intersect($route->methods(), self::WRITE_METHODS) as $method) {
                $key = $this->routeKey($method, $route->uri(), $route->getActionName());
                $liveWriteRoutes[$key] = true;
            }
        }

        // These POST requests authorize WebSocket subscriptions; they do not mutate records.
        $transportAuthorizationRoutes = [
            $this->routeKey('POST', 'api/v1/b2b/customer/broadcasting/auth', 'Illuminate\\Broadcasting\\BroadcastController@authenticate'),
            $this->routeKey('POST', 'api/v1/b2b/supplier/broadcasting/auth', 'Illuminate\\Broadcasting\\BroadcastController@authenticate'),
        ];
        foreach ($transportAuthorizationRoutes as $key) {
            $this->assertArrayNotHasKey($key, $inventoryByKey, 'Broadcast authorization is not a business mutation.');
            unset($liveWriteRoutes[$key]);
        }

        $expectedWriteRoutes = [];
        foreach ($inventoryByKey as $key => $row) {
            if (in_array($row['methods'], self::WRITE_METHODS, true)) {
                $expectedWriteRoutes[$key] = true;
            }
        }
        ksort($expectedWriteRoutes);
        ksort($liveWriteRoutes);
        $this->assertSame(
            $expectedWriteRoutes,
            $liveWriteRoutes,
            'Every live write route must be inventoried, and removed routes must leave the inventory.',
        );

        foreach ($inventoryByKey as $key => $row) {
            $matrixRow = $matrixByKey[$key] ?? null;
            $this->assertIsArray($matrixRow, $row['methods'].' '.$row['uri'].' needs a matrix row.');
            $this->assertNotSame('', trim((string) $row['source']), $row['methods'].' '.$row['uri'].' needs a source reference.');
            $this->assertNotSame(
                '',
                trim((string) $matrixRow['evidence_or_deferred_reason']),
                $row['methods'].' '.$row['uri'].' needs source evidence or an explicit deferred reason.',
            );

            if ($row['methods'] === 'GET') {
                $this->assertTrue(
                    collect(app('router')->getRoutes())->contains(fn ($route): bool => in_array('GET', $route->methods(), true)
                        && $route->uri() === $row['uri']
                        && $route->getActionName() === $row['controller_action']),
                    'Each inventoried GET side-effect route must still be registered.',
                );
            }
        }
    }

    /** @return list<array<string, string>> */
    private function readCsvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                return [];
            }
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            $rows = [];

            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    return [];
                }

                $row = array_combine($headers, $values);
                if (! is_array($row)) {
                    return [];
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function routeKey(string $method, string $uri, string $action): string
    {
        return implode("\0", [$method, $uri, ltrim($action, '\\')]);
    }

    /** @return array<string, list<class-string>> */
    private function applicationClassesByShortName(): array
    {
        $classes = [];
        foreach (File::allFiles(app_path()) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = File::get($file->getPathname());
            if (preg_match('/^namespace\s+([^;{]+)\s*;/m', $source, $namespace) !== 1
                || preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $class) !== 1) {
                continue;
            }

            $classes[$class[1]][] = trim($namespace[1]).'\\'.$class[1];
        }

        return $classes;
    }

    /** @param array<string, true> $visited */
    private function writerMethodReachesService(
        string $writerClass,
        string $methodName,
        string $producerClass,
        array &$visited,
    ): bool {
        $key = $writerClass.'::'.$methodName;
        if (isset($visited[$key]) || ! class_exists($writerClass) || ! method_exists($writerClass, $methodName)) {
            return false;
        }
        $visited[$key] = true;

        $method = new \ReflectionMethod($writerClass, $methodName);
        $file = $method->getFileName();
        if (! is_string($file)) {
            return false;
        }

        $classSource = File::get($file);
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return false;
        }
        $methodSource = implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        if ($this->methodReferencesServiceClass($methodSource, $classSource, $producerClass)) {
            return true;
        }

        foreach ($this->thisMethodCalls($methodSource) as $calledMethod) {
            if ($this->writerMethodReachesService($writerClass, $calledMethod, $producerClass, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function methodReferencesServiceClass(string $methodSource, string $classSource, string $producerClass): bool
    {
        $producerClass = ltrim($producerClass, '\\');
        $aliases = [];
        preg_match_all('/^\s*use\s+(?!function\b|const\b)([^;{}]+);/m', $classSource, $imports);
        foreach ($imports[1] ?? [] as $import) {
            $parts = preg_split('/\s+as\s+/i', trim($import));
            $className = ltrim(trim($parts[0]), '\\');
            $alias = trim($parts[1] ?? substr($className, strrpos($className, '\\') === false ? 0 : strrpos($className, '\\') + 1));
            $aliases[$alias] = $className;
        }

        // Reflection returns only the method body, without an opening PHP tag.
        // token_get_all() otherwise treats the whole snippet as inline HTML.
        $tokens = token_get_all("<?php\n".$methodSource);
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            $name = $token[1];
            $resolved = match ($token[0]) {
                T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED => ltrim($name, '\\'),
                T_STRING => $aliases[$name] ?? null,
                default => null,
            };
            if ($resolved !== $producerClass) {
                continue;
            }

            $next = $this->nextSignificantToken($tokens, $index + 1);
            if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
                $afterScopeIndex = $this->nextSignificantTokenIndex($tokens, $index + 2);
                $afterScope = $afterScopeIndex === null ? null : $tokens[$afterScopeIndex];

                if (is_array($afterScope) && $afterScope[0] === T_STRING) {
                    $call = $this->nextSignificantToken($tokens, $afterScopeIndex + 1);
                    if ($call === '(') {
                        return true;
                    }
                }

                if (is_array($afterScope) && $afterScope[0] === T_CLASS) {
                    $argumentOpenIndex = $this->previousSignificantTokenIndex($tokens, $index - 1);
                    $factoryIndex = $argumentOpenIndex === null
                        ? null
                        : $this->previousSignificantTokenIndex($tokens, $argumentOpenIndex - 1);
                    $factory = $factoryIndex === null ? null : $tokens[$factoryIndex];
                    $argumentClose = $this->nextSignificantToken($tokens, $afterScopeIndex + 1);
                    if ($argumentOpenIndex !== null
                        && $tokens[$argumentOpenIndex] === '('
                        && is_array($factory)
                        && $factory[0] === T_STRING
                        && in_array($factory[1], ['app', 'resolve'], true)
                        && $argumentClose === ')') {
                        $operatorIndex = $this->nextSignificantTokenIndex($tokens, $afterScopeIndex + 2);
                        if ($operatorIndex !== null && is_array($tokens[$operatorIndex])
                            && $tokens[$operatorIndex][0] === T_OBJECT_OPERATOR) {
                            $methodIndex = $this->nextSignificantTokenIndex($tokens, $operatorIndex + 1);
                            $call = $methodIndex === null
                                ? null
                                : $this->nextSignificantToken($tokens, $methodIndex + 1);
                            if ($methodIndex !== null && is_array($tokens[$methodIndex])
                                && $tokens[$methodIndex][0] === T_STRING && $call === '(') {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /** @param array<int, array{0:int,1:string,2:int}|string> $tokens */
    private function thisMethodCalls(string $methodSource): array
    {
        // Keep snippets in PHP mode so method calls are tokenized correctly.
        $tokens = token_get_all("<?php\n".$methodSource);
        $calls = [];
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
                continue;
            }

            $operator = $this->nextSignificantToken($tokens, $index + 1);
            if (! is_array($operator) || $operator[0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $methodIndex = $this->nextSignificantTokenIndex($tokens, $index + 2);
            if ($methodIndex === null) {
                continue;
            }
            $method = $tokens[$methodIndex];
            if (! is_array($method) || $method[0] !== T_STRING) {
                continue;
            }
            $openParen = $this->nextSignificantToken($tokens, $methodIndex + 1);
            if ($openParen === '(') {
                $calls[] = $method[1];
            }
        }

        return array_values(array_unique($calls));
    }

    /** @param array<int, array{0:int,1:string,2:int}|string> $tokens */
    private function nextSignificantToken(array $tokens, int $index): array|string|null
    {
        for ($count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @param array<int, array{0:int,1:string,2:int}|string> $tokens */
    private function nextSignificantTokenIndex(array $tokens, int $index): ?int
    {
        for ($count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /** @param array<int, array{0:int,1:string,2:int}|string> $tokens */
    private function previousSignificantTokenIndex(array $tokens, int $index): ?int
    {
        for (; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }
}
