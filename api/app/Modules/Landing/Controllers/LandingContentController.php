<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;

class LandingContentController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            // Customer names are operational data; do not publish the old
            // seeded OEM list, which could claim relationships that no longer
            // exist in this deployment.
            'oem_partners' => $this->activeCustomerNames(),
            'quality_methods' => $this->listSetting('landing.quality_methods'),
            'trust_points' => $this->liveTrustPoints(),
            // Proof points are generated from current operational records. The
            // previous CMS values included unverified headcount and compliance
            // claims that could become stale or misleading.
            'philippines_points' => $this->livePhilippinesPoints(),
            // Numeric proof points must describe the current ERP state. The
            // old landing.stats setting contained fabricated employee/OEM/
            // defect/OTD claims, so it is no longer exposed as public data.
            'stats' => $this->liveStats(),
            'capabilities' => $this->objectListSetting('landing.capabilities'),
            'process_steps' => $this->objectListSetting('landing.process_steps'),
            'quality_pillars' => $this->objectListSetting('landing.quality_pillars'),
            'quality_policy' => $this->objectSetting('landing.quality_policy'),
            'part_specs' => $this->objectListSetting('landing.part_specs'),
            'philippines_copy' => $this->objectSetting('landing.philippines_copy'),
            'hero_copy' => $this->objectSetting('landing.hero_copy'),
            'section_copy' => $this->objectSetting('landing.section_copy'),
        ]]);
    }

    /** @return list<string> */
    private function listSetting(string $key): array
    {
        $values = $this->settings->get($key, []);
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn ($value): bool => is_string($value) && trim($value) !== ''));
    }

    /** @return list<array<string, mixed>> */
    private function objectListSetting(string $key): array
    {
        $values = $this->settings->get($key, []);
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn ($value): bool => is_array($value)));
    }

    /** @return array<string, mixed> */
    private function objectSetting(string $key): array
    {
        $value = $this->settings->get($key, []);

        return is_array($value) ? $value : [];
    }

    /** @return list<array{id:string,value:int,label:string}> */
    private function liveStats(): array
    {
        return [
            ['id' => 'employees', 'value' => Employee::query()->where('status', 'active')->count(), 'label' => 'Active employees'],
            ['id' => 'customers', 'value' => Customer::query()->where('is_active', true)->count(), 'label' => 'Active customers'],
            ['id' => 'products', 'value' => Product::query()->where('is_active', true)->count(), 'label' => 'Active products'],
        ];
    }

    /** @return list<string> */
    private function activeCustomerNames(): array
    {
        return Customer::query()
            ->where('is_active', true)
            ->whereNotNull('name')
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function liveTrustPoints(): array
    {
        $customers = Customer::query()->where('is_active', true)->count();
        $products = Product::query()->where('is_active', true)->count();
        $standard = trim((string) ($this->objectSetting('landing.quality_policy')['standard'] ?? ''));

        return array_values(array_filter([
            $standard !== '' ? $standard : null,
            "{$customers} active customers",
            "{$products} active products",
        ], static fn ($value): bool => is_string($value) && $value !== ''));
    }

    /** @return list<array{value:string,label:string}> */
    private function livePhilippinesPoints(): array
    {
        return [
            ['value' => (string) Employee::query()->where('status', 'active')->count(), 'label' => 'Active employees'],
            ['value' => (string) Customer::query()->where('is_active', true)->count(), 'label' => 'Active customers'],
            ['value' => (string) Product::query()->where('is_active', true)->count(), 'label' => 'Active products'],
        ];
    }
}
