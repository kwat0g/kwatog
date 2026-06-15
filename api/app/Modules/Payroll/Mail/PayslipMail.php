<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Mail;

use App\Modules\Payroll\Models\Payroll;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayslipMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Payroll $payroll,
        private readonly string $pdfBinary,
        private readonly string $pdfFilename,
    ) {}

    public function envelope(): Envelope
    {
        $start = $this->payroll->period?->period_start?->format('Y-m-d') ?? 'period';
        return new Envelope(
            subject: "Payslip — {$start}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payslip',
            with: [
                'employee' => $this->payroll->employee,
                'period'   => $this->payroll->period,
                'payroll'  => $this->payroll,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }
}
