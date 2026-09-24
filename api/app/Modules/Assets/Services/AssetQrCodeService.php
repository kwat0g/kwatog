<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\Asset;

/**
 * Returns the JSON deep-link payload consumed by the SPA QR renderer.
 * This endpoint does not return SVG, PNG, or other image bytes.
 */
class AssetQrCodeService
{
    public function payload(Asset $asset): array
    {
        $url = rtrim((string) config('app.url'), '/').'/assets/'.$asset->hash_id;
        return [
            'asset_code' => $asset->asset_code,
            'name'       => $asset->name,
            'url'        => $url,
        ];
    }
}
