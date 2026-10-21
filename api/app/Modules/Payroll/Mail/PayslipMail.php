<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Mail;

use App\Modules\Payroll\Models\Payroll;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Payroll $payroll,
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
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.payslip',
            with: [
                'employee' => $this->payroll->employee,
                'period'   => $this->payroll->period,
                'payroll'  => $this->payroll,
                'payslipUrl' => $base.'/self-service/payslips',
            ],
        );
    }
}
