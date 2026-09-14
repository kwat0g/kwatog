<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Facades\DB;
use App\Common\Services\SettingsService;
use InvalidArgumentException;

/**
 * Atomic document number generator.
 *
 * Per-document-type configuration controls reset granularity:
 *   - 'monthly' (default): {PREFIX}-{YYYYMM}-{NNNN}
 *   - 'yearly':            {PREFIX}-{YYYY}-{NNNN}
 *
 * Concurrency safety: SELECT ... FOR UPDATE inside a serializable transaction
 * with row-level locking on the per-(type, year, month) row.
 */
class DocumentSequenceService
{
    /** Historical/demo imports can predate the sequence row. */
    private const PERSISTED_SOURCES = [
        'complaint' => ['customer_complaints', 'complaint_number'],
        'ncr'       => ['non_conformance_reports', 'ncr_number'],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, array{prefix: string, reset: 'monthly'|'yearly', pad: int}> */
    private function config(): array
    {
        $config = $this->settings->get('documents.sequence_config');
        if (! is_array($config) || $config === []) {
            throw new \App\Common\Exceptions\BusinessRuleException('Document sequence configuration is missing.');
        }
        foreach ($config as $type => $row) {
            if (! is_string($type) || ! is_array($row) || ! is_string($row['prefix'] ?? null)
                || ! in_array($row['reset'] ?? null, ['monthly', 'yearly'], true)
                || ! is_int($row['pad'] ?? null) || $row['pad'] < 1 || $row['pad'] > 12) {
                throw new \App\Common\Exceptions\BusinessRuleException('Document sequence configuration is invalid.');
            }
        }
        return $config;
    }

    /**
     * Generate the next document number for the given type.
     *
     * Format:
     *   monthly  →  {PREFIX}-{YYYYMM}-{NNNN}
     *   yearly   →  {PREFIX}-{YYYY}-{NNNN}
     */
    public function generate(string $documentType): string
    {
        $config = $this->config();
        if (! isset($config[$documentType])) {
            throw new InvalidArgumentException("Unknown document type: {$documentType}");
        }

        ['prefix' => $prefix, 'reset' => $reset, 'pad' => $pad] = $config[$documentType];

        $now = now();
        $year = (int) $now->format('Y');
        $month = $reset === 'yearly' ? 0 : (int) $now->format('n');

        return DB::transaction(function () use ($documentType, $prefix, $reset, $pad, $year, $month) {
            // Lock-or-create the sequence row.
            $row = DB::table('document_sequences')
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('document_sequences')->insert([
                    'document_type' => $documentType,
                    'prefix'        => $prefix,
                    'year'          => $year,
                    'month'         => $month,
                    'last_number'   => 0,
                ]);
                $row = DB::table('document_sequences')
                    ->where('document_type', $documentType)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->lockForUpdate()
                    ->first();
            }

            $datePart = $reset === 'yearly'
                ? sprintf('%04d', $year)
                : sprintf('%04d%02d', $year, $month);
            $persistedMax = $this->maxPersistedSequence($documentType, $prefix, $datePart);
            $next = max((int) $row->last_number, $persistedMax) + 1;
            DB::table('document_sequences')
                ->where('id', $row->id)
                ->update(['last_number' => $next]);

            return sprintf('%s-%s-%s', $prefix, $datePart, str_pad((string) $next, $pad, '0', STR_PAD_LEFT));
        });
    }

    private function maxPersistedSequence(string $documentType, string $prefix, string $datePart): int
    {
        $source = self::PERSISTED_SOURCES[$documentType] ?? null;
        if ($source === null) {
            return 0;
        }

        [$table, $column] = $source;
        $start = $prefix.'-'.$datePart.'-';

        return DB::table($table)
            ->where($column, 'like', $start.'%')
            ->pluck($column)
            ->map(static function (mixed $number): int {
                return preg_match('/(\d+)$/', (string) $number, $matches) === 1
                    ? (int) $matches[1]
                    : 0;
            })
            ->max() ?? 0;
    }

    /** @return array<int, string> */
    public function knownTypes(): array
    {
        return array_keys($this->config());
    }
}
