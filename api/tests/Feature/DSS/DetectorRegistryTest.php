<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Services\DetectorRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class FakeDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'test.fake';
    }

    public function domain(): string
    {
        return 'test';
    }

    public function ownerPermissions(): array
    {
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        return new DetectorScanResult([
            new DetectorEmission(type: 'fake', severity: 'info', dedupeKey: 'k', rationale: ['rule' => 'fake']),
        ]);
    }
}

class SecondFakeDetector extends FakeDetector
{
    public function id(): string
    {
        return 'test.second';
    }
}

class DetectorRegistryTest extends TestCase
{
    public function test_register_and_resolve(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.fake', FakeDetector::class);

        $this->assertTrue($registry->has('test.fake'));
        $this->assertInstanceOf(DetectorInterface::class, $registry->make('test.fake'));
        $this->assertSame(['test.fake' => FakeDetector::class], $registry->all());
    }

    public function test_unknown_detector_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DetectorRegistry)->make('nope.missing');
    }

    public function test_non_detector_class_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DetectorRegistry)->register('test.bad', \stdClass::class);
    }

    public function test_conflicting_id_registration_throws(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.fake', FakeDetector::class);

        $this->expectException(InvalidArgumentException::class);
        $registry->register('test.fake', SecondFakeDetector::class);
    }
}
