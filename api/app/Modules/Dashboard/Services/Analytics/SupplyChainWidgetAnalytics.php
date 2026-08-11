<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;

/** Filled in by Task 4. */
final class SupplyChainWidgetAnalytics
{
    /** @return list<string> */
    public function handles(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return [];
    }
}
