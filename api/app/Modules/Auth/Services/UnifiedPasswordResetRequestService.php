<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Services\SettingsService;
use App\Modules\B2B\Services\PortalPasswordResetService;
use Illuminate\Http\Request;

final class UnifiedPasswordResetRequestService
{
    public function __construct(
        private readonly PasswordResetService $internal,
        private readonly PortalPasswordResetService $portal,
        private readonly SettingsService $settings,
    ) {}

    public function send(string $email, Request $request): void
    {
        $this->internal->sendResetLink($email, $request);

        if ($this->settings->get('modules.b2b_portals', false) !== true) {
            return;
        }

        // Each reset service is deliberately enumeration-safe. Sending a
        // matching reset for every realm also handles a legitimate email that
        // belongs to both a customer and a supplier without a role selector.
        $this->portal->requestReset('customer', $email, $request);
        $this->portal->requestReset('supplier', $email, $request);
    }
}
