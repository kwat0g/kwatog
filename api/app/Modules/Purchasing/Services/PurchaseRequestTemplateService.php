<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequestTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PurchaseRequestTemplateService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = PurchaseRequestTemplate::query()
            ->with(['department:id,name,code', 'creator:id,name']);

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $q->where('name', 'ilike', '%' . $filters['search'] . '%');
        }

        return $q->orderBy('name')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(PurchaseRequestTemplate $template): array
    {
        $template->load(['department:id,name,code', 'creator:id,name']);
        return [
            'id'            => $template->id,
            'name'          => $template->name,
            'department'    => $template->department ? [
                'id'   => $template->department->hash_id,
                'name' => $template->department->name,
                'code' => $template->department->code,
            ] : null,
            'items'         => $template->items,
            'notes'         => $template->notes,
            'created_by'    => $template->creator?->name,
            'is_active'     => $template->is_active,
            'created_at'    => optional($template->created_at)->toIso8601String(),
        ];
    }

    public function create(array $data, User $by): PurchaseRequestTemplate
    {
        return PurchaseRequestTemplate::create([
            'name'          => $data['name'],
            'department_id' => $data['department_id'] ?? null,
            'items'         => $data['items'],
            'notes'         => $data['notes'] ?? null,
            'created_by'    => $by->id,
        ]);
    }

    public function update(PurchaseRequestTemplate $template, array $data): PurchaseRequestTemplate
    {
        $template->update([
            'name'          => $data['name']          ?? $template->name,
            'department_id' => $data['department_id']  ?? $template->department_id,
            'items'         => $data['items']          ?? $template->items,
            'notes'         => $data['notes']          ?? $template->notes,
            'is_active'     => $data['is_active']      ?? $template->is_active,
        ]);

        return $template->fresh();
    }

    public function delete(PurchaseRequestTemplate $template): void
    {
        $template->delete();
    }

    /**
     * Return all active templates, suitable for a "Use Template" picker.
     */
    public function allActive(): Collection
    {
        return PurchaseRequestTemplate::where('is_active', true)
            ->with(['department:id,name,code', 'creator:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => $this->show($t));
    }
}
