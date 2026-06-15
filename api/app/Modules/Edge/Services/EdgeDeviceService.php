<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\Edge\Enums\EdgeDeviceType;
use App\Modules\Edge\Models\EdgeDevice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

class EdgeDeviceService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = EdgeDevice::query();
        if (! empty($filters['device_type'])) {
            $q->where('device_type', $filters['device_type']);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($qq) => $qq
                ->where('serial_number', 'ilike', $term)
                ->orWhere('name', 'ilike', $term)
                ->orWhere('location', 'ilike', $term));
        }
        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data): EdgeDevice
    {
        $this->decodeMachineId($data);
        return DB::transaction(fn () => EdgeDevice::create($data));
    }

    public function update(EdgeDevice $device, array $data): EdgeDevice
    {
        $this->decodeMachineId($data);
        return DB::transaction(function () use ($device, $data) {
            $device->update($data);
            return $device->fresh();
        });
    }

    private function decodeMachineId(array &$data): void
    {
        if (! array_key_exists('machine_id', $data)) return;
        $hash = $data['machine_id'];
        if ($hash === null || $hash === '') {
            $data['machine_id'] = null;
            return;
        }
        $decoded = app('hashids')->decode((string) $hash);
        $data['machine_id'] = empty($decoded) ? null : (int) $decoded[0];
    }

    public function deactivate(EdgeDevice $device): EdgeDevice
    {
        return DB::transaction(function () use ($device) {
            $device->forceFill(['is_active' => false])->save();
            // Revoke all tokens on deactivation.
            $device->tokens()->delete();
            return $device->fresh();
        });
    }

    /**
     * Issue a new bearer token. Abilities are pinned to the device's type
     * so a scanner cannot post PLC counts even if its token is misused.
     */
    public function issueToken(EdgeDevice $device, string $tokenName, ?\DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $abilities = $device->device_type->abilities();
        return $device->createToken($tokenName, $abilities, $expiresAt);
    }

    public function revokeAllTokens(EdgeDevice $device): int
    {
        return (int) $device->tokens()->delete();
    }
}
