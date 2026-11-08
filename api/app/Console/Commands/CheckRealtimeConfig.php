<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Env;

/**
 * Detect cached Laravel realtime settings that no longer match the API
 * container's effective environment. Values are deliberately never printed.
 */
class CheckRealtimeConfig extends Command
{
    protected $signature = 'realtime:config-check';

    protected $description = 'Check cached broadcast and Reverb config against the current environment';

    private const SETTINGS = [
        'BROADCAST_CONNECTION' => ['broadcasting.default'],
        'REVERB_APP_ID' => ['broadcasting.connections.reverb.app_id', 'reverb.apps.apps.0.app_id'],
        'REVERB_APP_KEY' => ['broadcasting.connections.reverb.key', 'reverb.apps.apps.0.key'],
        'REVERB_APP_SECRET' => ['broadcasting.connections.reverb.secret', 'reverb.apps.apps.0.secret'],
        'REVERB_HOST' => ['reverb.apps.apps.0.options.host', 'reverb.servers.reverb.hostname'],
        'REVERB_PORT' => ['reverb.apps.apps.0.options.port'],
        'REVERB_SCHEME' => ['reverb.apps.apps.0.options.scheme'],
        'REVERB_SERVER_HOST' => ['reverb.servers.reverb.host'],
        'REVERB_SERVER_PORT' => ['reverb.servers.reverb.port'],
        'REVERB_SERVER_SCHEME' => ['broadcasting.connections.reverb.options.scheme'],
        'REVERB_APP_PING_INTERVAL' => ['reverb.apps.apps.0.ping_interval'],
        'REVERB_APP_ACTIVITY_TIMEOUT' => ['reverb.apps.apps.0.activity_timeout'],
        'REVERB_APP_MAX_MESSAGE_SIZE' => ['reverb.apps.apps.0.max_message_size'],
        'REVERB_MAX_REQUEST_SIZE' => ['reverb.servers.reverb.max_request_size'],
    ];

    public function handle(): int
    {
        $mismatches = [];

        foreach (self::SETTINGS as $variable => $paths) {
            $expected = Env::get($variable);
            if ($expected === null) {
                continue;
            }

            foreach ($paths as $path) {
                if ($this->differs($expected, config($path))) {
                    $this->addMismatch($mismatches, $variable);
                    break;
                }
            }
        }

        $this->compareFallback(
            $mismatches,
            Env::get('REVERB_SERVER_HOST') !== null ? 'REVERB_SERVER_HOST' : 'REVERB_HOST',
            Env::get('REVERB_SERVER_HOST') ?? Env::get('REVERB_HOST'),
            config('broadcasting.connections.reverb.options.host'),
        );
        $this->compareFallback(
            $mismatches,
            Env::get('REVERB_SERVER_PORT') !== null ? 'REVERB_SERVER_PORT' : 'REVERB_PORT',
            Env::get('REVERB_SERVER_PORT') ?? Env::get('REVERB_PORT'),
            config('broadcasting.connections.reverb.options.port'),
        );
        $this->compareFallback(
            $mismatches,
            Env::get('REVERB_SERVER_SCHEME') !== null ? 'REVERB_SERVER_SCHEME' : 'REVERB_SCHEME',
            Env::get('REVERB_SERVER_SCHEME') ?? Env::get('REVERB_SCHEME'),
            config('broadcasting.connections.reverb.options.scheme'),
        );

        $configuredOrigins = Env::get('REVERB_ALLOWED_ORIGINS');
        if (! is_string($configuredOrigins) || trim($configuredOrigins) === '') {
            $configuredOrigins = (string) (Env::get('FRONTEND_URL') ?: Env::get('APP_URL', 'http://localhost'));
        }

        $expectedOrigins = $this->normalizeOrigins($configuredOrigins);
        $actualOrigins = config('reverb.apps.apps.0.allowed_origins', []);
        if ($expectedOrigins !== [] && $actualOrigins !== $expectedOrigins) {
            $mismatches[] = 'REVERB_ALLOWED_ORIGINS';
        }

        if ($mismatches !== []) {
            $this->error('Cached realtime configuration differs from the current environment for: '.implode(', ', array_unique($mismatches)).'. Rebuild config cache and restart API, Horizon, and Reverb.');

            return self::FAILURE;
        }

        $this->info('Cached realtime configuration matches the current environment.');

        return self::SUCCESS;
    }

    /** @param list<string> $mismatches */
    private function compareFallback(array &$mismatches, string $variable, mixed $expected, mixed $actual): void
    {
        if ($expected !== null && $this->differs($expected, $actual)) {
            $this->addMismatch($mismatches, $variable);
        }
    }

    private function differs(mixed $expected, mixed $actual): bool
    {
        return (string) $actual !== (string) $expected;
    }

    /** @param list<string> $mismatches */
    private function addMismatch(array &$mismatches, string $variable): void
    {
        if (! in_array($variable, $mismatches, true)) {
            $mismatches[] = $variable;
        }
    }

    /** @return list<string> */
    private function normalizeOrigins(string $origins): array
    {
        return array_values(array_filter(array_map(
            static function (string $origin): string {
                $origin = trim($origin);

                return parse_url($origin, PHP_URL_HOST) ?: $origin;
            },
            explode(',', $origins),
        ), static fn (string $origin): bool => $origin !== ''));
    }
}
