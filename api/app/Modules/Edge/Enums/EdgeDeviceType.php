<?php

declare(strict_types=1);

namespace App\Modules\Edge\Enums;

enum EdgeDeviceType: string
{
    case BarcodeScanner = 'barcode_scanner';
    case PlcCounter     = 'plc_counter';
    case IotSensor      = 'iot_sensor';
    case Caliper        = 'caliper';
    case Scale          = 'scale';

    /** Token abilities pinned per device type. */
    public function abilities(): array
    {
        return match ($this) {
            self::BarcodeScanner => ['edge:scan'],
            self::PlcCounter     => ['edge:output'],
            self::IotSensor      => ['edge:condition'],
            self::Caliper, self::Scale => ['edge:measurement'],
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
