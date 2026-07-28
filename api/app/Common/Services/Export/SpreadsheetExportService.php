<?php

declare(strict_types=1);

namespace App\Common\Services\Export;

use App\Common\Enums\ExportFormat;
use App\Common\Exports\SpreadsheetExport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetExportService
{
    public function download(SpreadsheetExport $export, string $filename, ExportFormat $format = ExportFormat::Xlsx): StreamedResponse
    {
        return response()->streamDownload(
            fn () => $this->writer($this->spreadsheet($export, $format), $format)->save('php://output'),
            $filename,
            ['Content-Type' => $format->mimeType()],
        );
    }

    public function render(SpreadsheetExport $export, ExportFormat $format = ExportFormat::Xlsx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ogami-export-');
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate temporary export file.');
        }

        try {
            $this->writer($this->spreadsheet($export, $format), $format)->save($path);
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('Unable to read rendered export file.');
            }

            return $bytes;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function spreadsheet(SpreadsheetExport $export, ExportFormat $format): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($this->safeTitle($export->title()));

        $headings = $export->headings();
        $this->writeRow($sheet, 1, $headings, $format);
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headings)));
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '111111']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F4F4F5']],
        ]);
        $sheet->freezePane('A2');

        $rowNumber = 2;
        foreach ($export->collection() as $row) {
            $this->writeRow($sheet, $rowNumber, $export->map($row), $format);
            if ($rowNumber % 2 === 0) {
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFill()->applyFromArray([
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FAFAFA'],
                ]);
            }
            $rowNumber++;
        }

        for ($column = 1; $column <= count($headings); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        return $book;
    }

    /** @param array<int, mixed> $values */
    private function writeRow(Worksheet $sheet, int $row, array $values, ExportFormat $format): void
    {
        foreach (array_values($values) as $offset => $value) {
            $cell = Coordinate::stringFromColumnIndex($offset + 1).$row;
            if (is_string($value)) {
                if ($format === ExportFormat::Csv && preg_match('/^[\s]*[=+\-@]/u', $value) === 1) {
                    $value = "'".$value;
                }
                // User-authored values must never become executable spreadsheet formulas.
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
            } elseif ($value === null) {
                $sheet->setCellValue($cell, '');
            } else {
                $sheet->setCellValue($cell, $value);
            }
        }
    }

    private function writer(Spreadsheet $book, ExportFormat $format): Xlsx|Csv
    {
        if ($format === ExportFormat::Csv) {
            $writer = new Csv($book);
            $writer->setUseBOM(true);

            return $writer;
        }

        return new Xlsx($book);
    }

    private function safeTitle(string $title): string
    {
        $title = preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title) ?? 'Export';
        $title = preg_replace('/\s+/u', ' ', $title) ?? 'Export';

        return mb_substr(trim($title) ?: 'Export', 0, 31);
    }
}
