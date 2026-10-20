<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Mail;

use App\Common\Services\EmailBrandingService;
use App\Modules\Payroll\Models\Payroll;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class PayslipMail extends Mailable
{
    public function __construct(public readonly Payroll $payroll) {}

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();

        return new Envelope(
            subject: $this->safeHeaderText("[{$brand['name']}] Payslip available: {$this->periodLabel()}"),
        );
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.payslip',
            with: [
                'title' => 'Your payslip is ready',
                'preheader' => 'View your payslip for '.$this->periodLabel().' in the secure self-service portal.',
                'companyName' => $this->safeMarkdownText($brand['name'], 'the company'),
                'periodLabel' => $this->periodLabel(),
                'payslipUrl' => $frontendUrl.'/self-service/payslips/'.$this->payroll->hash_id,
            ],
        );
    }

    private function periodLabel(): string
    {
        $period = $this->payroll->period;
        $start = $period?->period_start ? Carbon::parse($period->period_start)->format('M d, Y') : 'start date unavailable';
        $end = $period?->period_end ? Carbon::parse($period->period_end)->format('M d, Y') : null;

        return $end ? "{$start} – {$end}" : $start;
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Payslip available');
    }

    private function safeMarkdownText(?string $value, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? $fallback : (preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback);
    }
}
