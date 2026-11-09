<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use Illuminate\Support\Str;
use mikehaertl\pdftk\XfdfFile;
use Symfony\Component\Process\Process;

final class SystemPdfTkProcessor implements PortalContractPdfProcessor
{
    public function __construct(private readonly string $binary = '/usr/local/bin/pdftk') {}

    public function discoverFields(string $pdfPath): array
    {
        $this->assertAvailable($pdfPath);
        $result = $this->run([$this->binary, $pdfPath, 'dump_data_fields']);
        $fields = [];
        foreach (preg_split('/\R---\R/', trim($result)) ?: [] as $block) {
            if (! preg_match('/^FieldName: (.+)$/m', $block, $name)) {
                continue;
            }
            preg_match('/^FieldType: (.+)$/m', $block, $type);
            $fields[] = ['name' => trim($name[1]), 'type' => isset($type[1]) ? trim($type[1]) : null];
        }

        return $fields;
    }

    public function fill(string $pdfPath, array $values): string
    {
        $this->assertAvailable($pdfPath);
        $directory = storage_path('app/private/portal-contract-tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Private contract processing storage is unavailable.');
        }

        $token = (string) Str::uuid();
        $xfdfPath = $directory.'/'.$token.'.xfdf';
        $outputPath = $directory.'/'.$token.'.pdf';
        try {
            $xfdf = new XfdfFile($values, null, null, $directory);
            if (! $xfdf->saveAs($xfdfPath)) {
                throw new \RuntimeException('The contract field data could not be prepared.');
            }
            chmod($xfdfPath, 0600);
            $this->run([$this->binary, $pdfPath, 'fill_form', $xfdfPath, 'output', $outputPath, 'need_appearances']);
            if (! is_file($outputPath) || filesize($outputPath) < 8 || file_get_contents($outputPath, false, null, 0, 5) !== '%PDF-') {
                throw new \RuntimeException('PDFtk did not produce a valid filled contract PDF.');
            }
            chmod($outputPath, 0600);

            return $outputPath;
        } catch (\Throwable $exception) {
            @unlink($outputPath);
            throw $exception;
        } finally {
            @unlink($xfdfPath);
        }
    }

    private function assertAvailable(string $pdfPath): void
    {
        if (! is_file($pdfPath) || ! is_readable($pdfPath)) {
            throw new \RuntimeException('The private contract PDF cannot be read.');
        }
        if (! is_executable($this->binary)) {
            throw new \RuntimeException('PDF form filling is not available on this server.');
        }
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $process = new Process($arguments);
        $process->setTimeout(20);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('PDF form processing failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }
}
