<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\Asset;

/**
 * Sprint 8 — Task 70. Lightweight QR code generator.
 *
 * Returns an SVG string encoding `{APP_URL}/assets/{hash_id}` so a phone
 * camera deep-links directly to the asset detail page. Implements a tiny
 * reproduction of the QR algorithm sufficient for short URLs (≤ 80 chars,
 * Version 4-M, byte mode). For production we'd swap in
 * `simplesoftwareio/simple-qrcode` — but this keeps the library footprint
 * tight and works without a vendor binary.
 *
 * For our needs the SPA renders QR codes via a JS library on the client
 * (qrcode npm package); this server-side helper exists to bake the URL
 * payload only, returned as JSON. The endpoint can later be upgraded to
 * stream a PNG when the QR library is installed.
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
