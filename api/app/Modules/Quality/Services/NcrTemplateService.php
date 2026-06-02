<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\NcrTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * ADV7 — NCR template lifecycle service.
 */
class NcrTemplateService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = NcrTemplate::query()
            ->with(['product:id,part_number,name', 'creator:id,name']);

        if (isset($filters['is_active'])) {
            $q->where('is_active', (bool) $filters['is_active']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(function ($b) use ($term) {
                $b->where('name', SearchOperator::like(), $term)
                  ->orWhere('defect_description', SearchOperator::like(), $term);
            });
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }

    public function show(NcrTemplate $template): NcrTemplate
    {
        return $template->load(['product:id,part_number,name', 'creator:id,name']);
    }

    public function create(array $data, User $by): NcrTemplate
    {
        $payload = [
            'name'               => $data['name'],
            'source'             => $data['source'],
            'severity'           => $data['severity'],
            'product_id'         => isset($data['product_id']) && $data['product_id'] !== ''
                ? (int) $data['product_id'] : null,
            'defect_description' => $data['defect_description'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'created_by'         => $by->id,
        ];
        $template = NcrTemplate::create($payload);
        return $this->show($template);
    }

    public function update(NcrTemplate $template, array $data): NcrTemplate
    {
        $update = [];
        foreach (['name', 'source', 'severity', 'defect_description', 'notes', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (array_key_exists('product_id', $data)) {
            $update['product_id'] = $data['product_id'] !== '' ? (int) $data['product_id'] : null;
        }
        if (! empty($update)) {
            $template->update($update);
        }
        return $this->show($template->fresh());
    }

    public function deactivate(NcrTemplate $template): NcrTemplate
    {
        $template->update(['is_active' => false]);
        return $this->show($template->fresh());
    }
}
