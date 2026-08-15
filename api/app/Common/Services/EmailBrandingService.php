<?php

declare(strict_types=1);

namespace App\Common\Services;

/**
 * Single source of truth for transactional email identity.
 *
 * The legal/company values come from the existing company settings. The
 * display name is intentionally separate: customer-facing mail should say
 * "Ogami Philippines" while official documents may continue to use the
 * configured legal entity name.
 */
class EmailBrandingService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, string|null> */
    public function data(): array
    {
        return [
            'name' => $this->value('email.brand_name', 'Ogami Philippines'),
            'legal_name' => $this->value('company.legal_name', 'Philippine Ogami Corporation'),
            'address' => $this->value('company.address', 'FCIE Complex, Dasmariñas, Cavite, Philippines'),
            'phone' => $this->value('company.phone', '+63 46 000 0000'),
            'email' => $this->value('company.email', 'info@ogami.test'),
            'sales_email' => $this->value('company.sales_inbox_email', 'sales@ogami.com.ph'),
            'tin' => $this->value('company.tin', '000-000-000-000'),
            'vat_status' => $this->value('company.vat_status', 'VAT Registered'),
            'certification' => $this->value('company.certification', 'IATF 16949:2016 Certified'),
            'public_url' => rtrim($this->value('company.public_url', config('app.frontend_url', config('app.url'))), '/'),
            'logo_path' => $this->logoPath(),
            'logo_data_uri' => $this->logoDataUri(),
        ];
    }

    public function logoPath(): ?string
    {
        $path = resource_path('images/ogami-mark.png');
        if (is_file($path)) {
            return $path;
        }

        $path = resource_path('images/ogami-mark.svg');

        return is_file($path) ? $path : null;
    }

    public function logoDataUri(): ?string
    {
        $path = resource_path('images/ogami-mark.svg');
        if (! is_file($path)) {
            $path = $this->logoPath();
        }

        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/svg+xml';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private function value(string $key, string $fallback): string
    {
        $configured = $this->settings->get($key);
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $envKey = strtoupper(str_replace(['.', '-'], '_', $key));
        $environment = env($envKey);
        if (is_string($environment) && trim($environment) !== '') {
            return trim($environment);
        }

        return $fallback;
    }
}
