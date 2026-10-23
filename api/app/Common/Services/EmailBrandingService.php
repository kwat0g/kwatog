<?php

declare(strict_types=1);

namespace App\Common\Services;

/**
 * Single source of truth for transactional email identity.
 *
 * Company identity comes from the existing company settings.
 */
class EmailBrandingService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, string|null> */
    public function data(): array
    {
        return [
            'name' => $this->value('email.brand_name') ?? $this->value('company.legal_name'),
            'legal_name' => $this->value('company.legal_name'),
            'address' => $this->value('company.address'),
            'phone' => $this->value('company.phone'),
            'email' => $this->value('company.email'),
            'sales_email' => $this->value('company.sales_inbox_email'),
            'tin' => $this->value('company.tin'),
            'vat_status' => $this->value('company.vat_status'),
            'certification' => $this->value('company.certification'),
            'public_url' => rtrim($this->value('company.public_url') ?? config('app.frontend_url', config('app.url')), '/'),
            'logo_path' => $this->logoPath(),
            'logo_data_uri' => $this->logoDataUri(),
        ];
    }

    public function logoPath(): ?string
    {
        foreach (['images/ogami-logo.png', 'images/ogami-mark.png', 'images/ogami-mark.svg'] as $relative) {
            $path = resource_path($relative);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
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
     * Settings row only. There is deliberately no env()
     * tier: production runs `php artisan config:cache`
     * (docker/php/prod-entrypoint.sh:15), after which env() returns null, so
     * an env tier fires only in development and silently diverges from
     * production. Every branding key is seeded into settings from env at
     * migrate/seed time — migration 0123 for the company.* keys,
     * SettingsSeeder for email.brand_name and company.sales_inbox_email —
     * which is where environment values legitimately enter the system.
     */
    private function value(string $key): ?string
    {
        $configured = $this->settings->get($key);
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return null;
    }
}
