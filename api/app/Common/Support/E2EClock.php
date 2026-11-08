<?php

declare(strict_types=1);

namespace App\Common\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class E2EClock
{
    public const CACHE_KEY = 'e2e:clock:instant';

    public function freeze(string $instant): CarbonImmutable
    {
        E2EEnvironment::assertEnabled();
        $frozen = CarbonImmutable::parse($instant)->utc();
        Cache::store('redis')->put(self::CACHE_KEY, $frozen->toIso8601String(), 60 * 24 * 30);
        $this->apply($frozen->toIso8601String());
        $this->synchronizeSessionActivity($frozen);

        return $frozen;
    }

    public function clear(): void
    {
        E2EEnvironment::assertEnabled();
        Cache::store('redis')->forget(self::CACHE_KEY);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        $this->synchronizeSessionActivity(CarbonImmutable::now()->utc());
    }

    public function apply(?string $instant = null): void
    {
        if (! config('app.e2e.enabled')) {
            return;
        }

        E2EEnvironment::assertEnabled();
        $instant ??= Cache::store('redis')->get(self::CACHE_KEY);
        $frozen = $instant === null ? null : CarbonImmutable::parse($instant)->utc();
        Carbon::setTestNow($frozen);
        CarbonImmutable::setTestNow($frozen);
    }

    /**
     * Keep cookie-backed E2E actors alive in the same simulated time domain.
     * Laravel's database session handler writes last_activity from the host
     * clock, while SessionTimeout compares it to Carbon::now() (the E2E clock).
     * Without rebasing this infrastructure timestamp, a valid E2E time jump
     * expires every authenticated browser session before its next UI action.
     */
    private function synchronizeSessionActivity(CarbonImmutable $instant): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))->update([
            'last_activity' => $instant->timestamp,
        ]);
    }
}
