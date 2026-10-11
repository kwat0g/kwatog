<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The dashboard date range, resolved once on the server.
 *
 * Phase 0 E §3 gap: `PeriodControl` existed on the SPA but the backend had no
 * `rangeBounds()`, so per-shift and payroll-cutoff presets could not be offered
 * and each page re-derived its own window. This is that resolver. Presets are
 * closed ranges in the app timezone (Asia/Manila by default) and `previous()`
 * yields the same-length window immediately before — the comparison a KPI delta
 * needs, so a caller cannot accidentally compare a month against a week.
 */
final readonly class AnalyticsPeriod
{
    public const PRESETS = ['today', 'week', 'month', 'quarter', 'ytd', 'custom'];

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $preset,
    ) {
        if ($this->to->lessThan($this->from)) {
            throw new InvalidArgumentException('Analytics period end must not precede its start.');
        }
    }

    public static function preset(string $preset, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        [$from, $to] = match ($preset) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->startOfWeek()->startOfDay(), $now->endOfWeek()->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfMonth()],
            'quarter' => [$now->startOfQuarter()->startOfDay(), $now->endOfQuarter()->endOfDay()],
            'ytd' => [$now->startOfYear(), $now->endOfDay()],
            default => throw new InvalidArgumentException("Unknown analytics period preset [{$preset}]."),
        };

        return new self($from, $to, $preset);
    }

    public static function between(CarbonImmutable $from, CarbonImmutable $to, string $preset = 'custom'): self
    {
        return new self($from->startOfDay(), $to->endOfDay(), $preset);
    }

    /**
     * Build from validated request input. Defaults to the current month, which
     * is the window every bespoke dashboard already opens on.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $preset = is_string($input['period'] ?? null) ? $input['period'] : 'month';
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;

        if ($preset === 'custom' || ($from !== null && $to !== null)) {
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('A custom analytics period needs both `from` and `to`.');
            }

            return self::between(CarbonImmutable::parse((string) $from), CarbonImmutable::parse((string) $to));
        }

        return self::preset($preset);
    }

    /** The equal-length window immediately before this one (delta comparison). */
    public function previous(): self
    {
        $days = $this->days();

        return new self(
            $this->from->subDays($days),
            $this->to->subDays($days),
            $this->preset,
        );
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** @return array<string, mixed> */
    public function toMeta(): array
    {
        $previous = $this->previous();

        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
            'compared_from' => $previous->from->toDateString(),
            'compared_to' => $previous->to->toDateString(),
            'timezone' => (string) config('app.timezone'),
        ];
    }
}
