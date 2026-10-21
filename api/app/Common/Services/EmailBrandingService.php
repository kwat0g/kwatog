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
            'legal_name' => $this->settings->requiredString('company.legal_name'),
            'address' => $this->settings->requiredString('company.address'),
            'phone' => $this->value('company.phone'),
            'email' => $this->value('company.email'),
            'sales_email' => $this->value('company.sales_inbox_email'),
            'tin' => $this->value('company.tin'),
            'vat_status' => $this->value('company.vat_status'),
            'certification' => $this->value('company.certification'),
            'footer_disclaimer' => $this->value('email.footer_disclaimer'),
            'public_url' => rtrim($this->value('company.public_url', config('app.frontend_url', config('app.url'))), '/'),
            'logo_path' => $this->logoPath(),
            'logo_dark_path' => $this->logoDarkPath(),
            'logo_data_uri' => $this->logoDataUri(),
        ];
    }

    public function logoPath(): ?string
    {
        $path = resource_path('images/ogami-logo.png');
        if (is_file($path)) {
            return $path;
        }

        $path = resource_path('images/ogami-mark.png');
        if (is_file($path)) {
            return $path;
        }

        $path = resource_path('images/ogami-mark.svg');

        return is_file($path) ? $path : null;
    }

    public function logoDarkPath(): ?string
    {
        $path = resource_path('images/ogami-mark-dark.png');

        return is_file($path) ? $path : $this->logoPath();
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

    /**
     * Settings row, then an optional display-only fallback. Legal identity
     * fields are required above so missing deployment data cannot be presented
     * as company fact. Values enter settings through migrations and seeders.
     */
    private function value(string $key, ?string $fallback = null): ?string
    {
        $configured = $this->settings->get($key);
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return $fallback;
    }
}
