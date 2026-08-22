<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Common\Enums\ExportFormat;
use App\Common\Mail\ScheduledExportMail;
use Closure;
use Illuminate\Mail\Mailables\Attachment;
use Tests\TestCase;

class ScheduledExportMailTest extends TestCase
{
    public function test_binary_attachment_survives_queue_safe_encoding(): void
    {
        $bytes = "PK\x03\x04\x00\xFF\x80binary";
        $mail = new ScheduledExportMail('Employees', 'hr.employees', 'employees.xlsx', $bytes, ExportFormat::Xlsx);

        $attachment = $mail->attachments()[0];
        $resolved = $attachment->attachWith(
            static fn (string $path): string => $path,
            static fn (Closure $data, Attachment $attachment): string => $data(),
        );

        $this->assertSame($bytes, $resolved);
    }
}
