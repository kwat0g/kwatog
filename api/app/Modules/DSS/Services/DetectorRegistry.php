<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Modules\DSS\Contracts\DetectorInterface;
use InvalidArgumentException;

/**
 * Code registry of detectors: id → class. Runtime state (enabled, mode,
 * shadow) lives in `dss_parameters`, never here — deploying a detector and
 * turning it on are separate decisions (kill switch included).
 */
class DetectorRegistry
{
    /** @var array<string, class-string<DetectorInterface>> */
    private array $detectors = [];

    /** @param class-string<DetectorInterface> $class */
    public function register(string $id, string $class): void
    {
        if (! is_subclass_of($class, DetectorInterface::class)) {
            throw new InvalidArgumentException("{$class} must implement DetectorInterface.");
        }

        if (isset($this->detectors[$id]) && $this->detectors[$id] !== $class) {
            throw new InvalidArgumentException("Detector id '{$id}' is already registered.");
        }

        $this->detectors[$id] = $class;
    }

    /** @return array<string, class-string<DetectorInterface>> */
    public function all(): array
    {
        return $this->detectors;
    }

    public function has(string $id): bool
    {
        return isset($this->detectors[$id]);
    }

    public function make(string $id): DetectorInterface
    {
        if (! isset($this->detectors[$id])) {
            throw new InvalidArgumentException("Unknown detector '{$id}'.");
        }

        return app($this->detectors[$id]);
    }
}
