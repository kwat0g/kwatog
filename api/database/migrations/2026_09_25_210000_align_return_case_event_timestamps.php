<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $databaseZone = DB::selectOne("SELECT current_setting('TIMEZONE') AS zone")->zone;
        $applicationZone = config('app.timezone', 'UTC');
        // The submitted event and case are written in one transaction. Repair
        // only histories whose submission proves the database/app clock offset.
        // Imported history has explicit timestamps and must never be shifted.
        DB::table('return_cases')->whereNull('legacy_discrepancy_id')->orderBy('id')->each(function ($case) use ($databaseZone, $applicationZone): void {
            $submitted = DB::table('return_case_events')->where('return_case_id', $case->id)->where('action', 'submitted')->orderBy('id')->first();
            if (! $submitted) {
                return;
            }
            $metadata = json_decode($submitted->metadata ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            if (isset($metadata['timestamp_timezone']) || isset($metadata['timeline_timestamp_repair'])) {
                return;
            }
            $caseTime = CarbonImmutable::parse($case->created_at, $applicationZone);
            $oldTime = CarbonImmutable::parse($submitted->created_at, $applicationZone);
            $correctTime = CarbonImmutable::parse($submitted->created_at, $databaseZone)->setTimezone($applicationZone);
            if (abs($caseTime->diffInSeconds($oldTime)) <= 60 || abs($caseTime->diffInSeconds($correctTime)) > 60) {
                return;
            }
            DB::table('return_case_events')->where('return_case_id', $case->id)->orderBy('id')->each(function ($event) use ($databaseZone, $applicationZone): void {
                $metadata = json_decode($event->metadata ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                if (isset($metadata['timestamp_timezone']) || isset($metadata['timeline_timestamp_repair'])) {
                    return;
                }
                $correctTime = CarbonImmutable::parse($event->created_at, $databaseZone)->setTimezone($applicationZone)->format('Y-m-d H:i:s');
                $metadata['timeline_timestamp_repair'] = ['original' => $event->created_at, 'corrected' => $correctTime,
                    'database_timezone' => $databaseZone, 'application_timezone' => $applicationZone];
                DB::table('return_case_events')->where('id', $event->id)->update([
                    'created_at' => $correctTime, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                ]);
            });
        });
    }

    public function down(): void
    {
        DB::table('return_case_events')->whereNotNull('metadata')->orderBy('id')->each(function ($event): void {
            $metadata = json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR);
            $repair = $metadata['timeline_timestamp_repair'] ?? null;
            if (! $repair || $event->created_at !== $repair['corrected']) {
                return;
            }
            unset($metadata['timeline_timestamp_repair']);
            DB::table('return_case_events')->where('id', $event->id)->update([
                'created_at' => $repair['original'], 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        });
    }
};
