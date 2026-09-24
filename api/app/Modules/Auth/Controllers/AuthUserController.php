<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Common\Rules\StrongPassword;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Resources\UserResource;
use App\Common\Services\SettingsService;
use Illuminate\Http\Request;

class AuthUserController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function __invoke(Request $request): UserResource
    {
        $user = $request->user();

        // OGAMI audit DEFECT-3 — this endpoint is the SPA's identity bootstrap
        // and resolves under auth:sanctum. A B2B portal bearer token (whose
        // tokenable is a SupplierPortalUser / CustomerPortalUser, NOT a User)
        // can satisfy the sanctum guard; loading the User-only `role.permissions`
        // relation on it 500'd. Reject any non-User principal with a clean 401
        // instead of crashing.
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return new UserResource($user->load(['role.permissions']));
    }

    public function passwordPolicy(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => [
            'minimum_length' => $this->settings->requiredInt('security.password_min_length', 1),
            'requires_uppercase' => StrongPassword::REQUIRE_UPPERCASE,
            'requires_lowercase' => StrongPassword::REQUIRE_LOWERCASE,
            'requires_digit' => StrongPassword::REQUIRE_DIGIT,
            'requires_special' => StrongPassword::REQUIRE_SPECIAL,
        ]]);
    }
}
