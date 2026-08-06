<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class QualityPolicyController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function download(): Response
    {
        // Publish only current operational customers; the legacy CMS partner
        // list could claim relationships that are not present in this ERP.
        $partners = Customer::query()
            ->where('is_active', true)
            ->whereNotNull('name')
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($partner): string => trim((string) $partner))
            ->filter(static fn (string $partner): bool => $partner !== '')
            ->values()
            ->all();
        $objectives = array_values(array_filter((array) $this->settings->get('landing.quality_policy.objectives', []), static fn ($objective): bool => is_array($objective) && isset($objective['title'], $objective['body'])));
        $policy = (array) $this->settings->get('landing.quality_policy', []);
        $standard = (string) ($policy['standard'] ?? '');
        $companyName = (string) $this->settings->get('company.legal_name', 'PHILIPPINE OGAMI CORPORATION');
        $companyAddress = (string) $this->settings->get('company.address', 'First Cavite Industrial Estate (FCIE), Dasmariñas, Cavite, Philippines');
        $replace = static fn (string $text): string => strtr($text, [
            '{{company}}' => $companyName,
            '{{standard}}' => $standard,
            '{{partners}}' => implode(', ', $partners),
        ]);
        $pdf = Pdf::loadView('pdf.quality-policy', [
            // Fixed approval/effective date of the controlled document (Rev. A);
            // `generatedAt` is only the "printed on" stamp in the footer.
            'effectiveDate' => (string) $this->settings->get('landing.quality_policy.effective_date', ''),
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'qualityStandard' => $standard,
            'commitmentBody' => $replace((string) ($policy['commitment_body'] ?? '')),
            'systemBody' => $replace((string) ($policy['system_body'] ?? '')),
            'partners' => $partners,
            'objectives' => $objectives,
            'generatedAt'   => now()->format('d F Y'),
        ]);

        return $pdf->download('ogami-quality-policy.pdf');
    }
}
