<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Enums\ExportFormat;
use App\Common\Exports\SpreadsheetExport;
use App\Common\Services\Export\SpreadsheetExportService;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SpreadsheetExportServiceTest extends TestCase
{
    private function export(): SpreadsheetExport
    {
        return new class implements SpreadsheetExport
        {
            public function collection(): Collection
            {
                return collect([
                    ['name' => '=HYPERLINK("https://example.test","click")', 'amount' => 12.5],
                ]);
            }

            public function headings(): array
            {
                return ['Name', 'Amount'];
            }

            public function map(mixed $row): array
            {
                return [$row['name'], $row['amount']];
            }

            public function title(): string
            {
                return 'Unsafe / title: test';
            }
        };
    }

    public function test_xlsx_preserves_user_strings_without_turning_them_into_formulas(): void
    {
        $bytes = app(SpreadsheetExportService::class)->render($this->export());
        $path = tempnam(sys_get_temp_dir(), 'spreadsheet-test-');
        $this->assertNotFalse($path);

        try {
            $this->assertNotFalse(file_put_contents($path, $bytes));
            $book = IOFactory::load($path);
            $cell = $book->getActiveSheet()->getCell('A2');

            $this->assertSame('=HYPERLINK("https://example.test","click")', $cell->getValue());
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
            $this->assertSame('Unsafe title test', $book->getActiveSheet()->getTitle());
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_csv_output_has_a_utf8_bom_and_expected_rows(): void
    {
        $bytes = app(SpreadsheetExportService::class)->render($this->export(), ExportFormat::Csv);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $bytes);
        $this->assertStringContainsString('"Name","Amount"', $bytes);
        $this->assertStringContainsString('"\'=HYPERLINK(""https://example.test"",""click"")","12.5"', $bytes);
    }
}
