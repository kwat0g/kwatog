<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

enum ProductionLogEvent: string
{
    case StartSetup      = 'start_setup';
    case EndSetup        = 'end_setup';
    case StartProduction = 'start_production';
    case Pause           = 'pause';
    case Resume          = 'resume';
    case RecordOutput    = 'record_output';
    case RecordScrap     = 'record_scrap';
    case EndProduction   = 'end_production';
    case DowntimeStart   = 'downtime_start';
    case DowntimeEnd     = 'downtime_end';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::StartSetup      => 'Start Setup',
            self::EndSetup        => 'End Setup',
            self::StartProduction => 'Start Production',
            self::Pause           => 'Pause',
            self::Resume          => 'Resume',
            self::RecordOutput    => 'Record Output',
            self::RecordScrap     => 'Record Scrap',
            self::EndProduction   => 'End Production',
            self::DowntimeStart   => 'Downtime Start',
            self::DowntimeEnd     => 'Downtime End',
        };
    }
}
