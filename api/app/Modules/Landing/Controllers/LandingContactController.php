<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Common\Services\SettingsService;
use Illuminate\Http\JsonResponse;

class LandingContactController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'legal_name' => $this->nullableString('company.legal_name'),
            'address' => $this->nullableString('company.address'),
            'phone' => $this->nullableString('company.phone'),
            'sales_email' => $this->nullableString('company.sales_inbox_email'),
            'company_email' => $this->nullableString('company.email'),
            'public_url' => $this->nullableString('company.public_url'),
            'latitude' => $this->nullableFloat('company.latitude', -90, 90),
            'longitude' => $this->nullableFloat('company.longitude', -180, 180),
        ]]);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->settings->get($key);
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function nullableFloat(string $key, float $minimum, float $maximum): ?float
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value)) return null;
        $number = (float) $value;
        return $number >= $minimum && $number <= $maximum ? $number : null;
    }
}
