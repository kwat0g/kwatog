<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class JournalEntryMutationContractTest extends TestCase
{
    public function test_application_journal_entry_mutations_are_canonical(): void
    {
        $canonical = str_replace('\\', '/', base_path('app/Modules/Accounting/Services/JournalEntryService.php'));
        $patterns = [
            '~DB::table\(\s*[\'\"]journal_entries[\'\"]\s*\)(?:(?!;).)*->(?:update|insert|insertGetId|upsert|delete|truncate)\s*\(~s',
            '~JournalEntry::(?:query|where|find|findOrFail)\([^;]*\)(?:(?!;).)*->update\s*\(~s',
        ];

        foreach (File::allFiles(base_path('app')) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($path === $canonical) {
                continue;
            }

            $source = File::get($file->getPathname());
            foreach ($patterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $source,
                    "Direct journal_entries mutation found outside JournalEntryService: {$path}",
                );
            }
        }
    }

    public function test_automated_gl_writers_depend_on_the_canonical_service(): void
    {
        $writers = [
            'Modules/Accounting/Services/BillService.php',
            'Modules/Accounting/Services/CreditNoteService.php',
            'Modules/Accounting/Services/InvoiceService.php',
            'Modules/Assets/Services/AssetService.php',
            'Modules/Assets/Services/DepreciationService.php',
            'Modules/HR/Services/FinalPayService.php',
            'Modules/Inventory/Services/GrnGlPostingService.php',
            'Modules/Inventory/Services/MovementGlPostingService.php',
            'Modules/Payroll/Services/PayrollGlPostingService.php',
        ];

        foreach ($writers as $relative) {
            $path = app_path($relative);
            $this->assertFileExists($path);
            $source = File::get($path);
            $this->assertStringContainsString(
                'JournalEntryService',
                $source,
                "Automated GL writer does not reference JournalEntryService: {$relative}",
            );
            $this->assertMatchesRegularExpression(
                '/->journals->(?:create|post|postSystem|reverse)\s*\(/',
                $source,
                "Automated GL writer does not call the canonical journal service: {$relative}",
            );
        }
    }
}
