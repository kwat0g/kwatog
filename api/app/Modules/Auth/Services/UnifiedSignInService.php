<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Services\B2bAuthService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UnifiedSignInService
{
    /**
     * @var array<string, array{model: class-string<Model>, audience: string, guard: string}>
     */
    private const PORTAL_REALMS = [
        'customer' => [
            'model' => CustomerPortalUser::class,
            'audience' => 'customer',
            'guard' => 'customer_portal',
        ],
        'supplier' => [
            'model' => SupplierPortalUser::class,
            'audience' => 'supplier',
            'guard' => 'supplier_portal',
        ],
    ];

    public function __construct(
        private readonly AuthService $internalAuth,
        private readonly B2bAuthService $portalAuth,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{realm: 'internal'|'customer'|'supplier', user: User|Model}
     */
    public function login(string $email, string $password, Request $request): array
    {
        $email = strtolower(trim($email));
        $candidates = $this->candidates($email);

        if ($candidates === []) {
            return [
                'realm' => 'internal',
                'user' => $this->internalAuth->login($email, $password, $request),
            ];
        }

        $matches = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => Hash::check($password, $candidate['user']->getAttribute('password')),
        ));

        if (count($matches) > 1) {
            throw ValidationException::withMessages([
                'email' => 'This email is linked to more than one Ogami account. Contact support to continue.',
            ]);
        }

        // With a unique email, dispatch only to the realm whose password
        // matched. This avoids counting a valid portal password as a failed
        // internal login when the same email is present in another realm.
        $candidate = $matches[0] ?? $candidates[0];

        if ($candidate['realm'] === 'internal') {
            return [
                'realm' => 'internal',
                'user' => $this->internalAuth->login($email, $password, $request),
            ];
        }

        $config = self::PORTAL_REALMS[$candidate['realm']];

        return [
            'realm' => $candidate['realm'],
            'user' => $this->portalAuth->login(
                modelClass: $config['model'],
                email: $email,
                password: $password,
                request: $request,
                audience: $config['audience'],
                sessionGuard: $config['guard'],
            ),
        ];
    }

    /**
     * @return list<array{realm: 'internal'|'customer'|'supplier', user: Model}>
     */
    private function candidates(string $email): array
    {
        $candidates = [];
        $internal = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($internal) {
            $candidates[] = ['realm' => 'internal', 'user' => $internal];
        }

        // A disabled portal module must not be discoverable through the
        // unified entry point. The internal ERP login remains independent.
        if ($this->settings->get('modules.b2b_portals', false) !== true) {
            return $candidates;
        }

        foreach (self::PORTAL_REALMS as $realm => $config) {
            $user = $config['model']::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user) {
                $candidates[] = ['realm' => $realm, 'user' => $user];
            }
        }

        return $candidates;
    }
}
