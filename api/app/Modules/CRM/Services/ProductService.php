<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Services\FinishedGoodInventoryService;
use App\Modules\MRP\Models\Bom;
use App\Modules\Quality\Models\InspectionSpec;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Product::query();

        TrashedFilter::apply($q, $filters);

        // The MRP and Quality tables are optional from CRM's point of view.
        // Enrich the response when their schemas are present, but keep product
        // CRUD usable during an install or migration window where they are not.
        $hasBomSchema = $this->hasBomSchema();
        if ($hasBomSchema) {
            $hasBomSubquery = '(SELECT 1 FROM bill_of_materials b
                               WHERE b.product_id = products.id
                                 AND b.is_active = true
                                 AND b.deleted_at IS NULL
                               LIMIT 1)';
            $q->selectRaw("products.*, COALESCE(({$hasBomSubquery}), 0) as has_bom_flag");
        } else {
            $q->select('products.*');
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('part_number', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhere('name', SearchOperator::like(), SearchOperator::contains($term));
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['has_bom']) && $filters['has_bom'] !== '') {
            $wantsBom = filter_var($filters['has_bom'], FILTER_VALIDATE_BOOLEAN);
            if ($hasBomSchema) {
                $q->whereRaw(($wantsBom ? '' : 'NOT ')."EXISTS {$hasBomSubquery}");
            } elseif ($wantsBom) {
                $q->whereRaw('1 = 0');
            }
        }

        return $q->orderBy('part_number')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Product $product): Product
    {
        $relations = ['portalCustomers:id,name', 'inventoryItem'];
        if ($this->hasBomSchema()) {
            $relations[] = 'activeBom:id,product_id,version,is_active';
        }
        if ($this->hasInspectionSpecSchema()) {
            $relations[] = 'inspectionSpec:id,product_id,version,is_active,updated_at';
        }

        return $relations === [] ? $product : $product->load($relations);
    }

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $data = $this->canonicalizeUom($data);
            $data = $this->normalisePartNumber($data);
            $this->assertUniquePartNumber((string) $data['part_number']);

            $customers = $data['portal_customer_ids'] ?? [];
            unset($data['portal_customer_ids']);
            $this->assertSellingPrices($data);
            $product = Product::create($data)->fresh();
            $product->portalCustomers()->sync($customers);
            if ($product->is_active) {
                app(FinishedGoodInventoryService::class)->ensureItem($product);
            }

            return $product->load('portalCustomers:id,name');
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $data = $this->canonicalizeUom($data);
            $data = $this->normalisePartNumber($data);
            $changesIdentity = (isset($data['part_number']) && $data['part_number'] !== $locked->part_number)
                || (isset($data['unit_of_measure']) && strcasecmp($data['unit_of_measure'], $locked->unit_of_measure) !== 0);
            if ($changesIdentity && (Item::withTrashed()->where('code', $locked->part_number)->exists()
                || ($this->hasBomSchema() && Bom::withTrashed()->where('product_id', $locked->id)->exists())
                || DB::table('work_orders')->where('product_id', $locked->id)->exists()
                || $locked->salesOrderItems()->exists() || $locked->priceAgreements()->withTrashed()->exists()
                || PriceRequest::where('product_id', $locked->id)->whereIn('status', ['requested', 'pending_finance', 'offered'])->exists())) {
                throw new BusinessRuleException('Cannot change product part number or base unit after inventory mapping, BOM, production, sales, or price agreements use it.');
            }
            if (isset($data['part_number'])) {
                $this->assertUniquePartNumber((string) $data['part_number'], (int) $locked->id);
            }
            if (
                $locked->is_active
                && isset($data['is_active'])
                && ! filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN)
            ) {
                $this->assertCanArchive($locked);
            }
            $customers = $data['portal_customer_ids'] ?? null;
            unset($data['portal_customer_ids']);
            $financialChange = collect(['standard_cost', 'minimum_selling_price', 'base_selling_price'])->contains(fn ($field) => array_key_exists($field, $data)
                && (($data[$field] === null) !== ($locked->{$field} === null) || ($data[$field] !== null && Money::cmp((string) $data[$field], (string) ($locked->{$field} ?? '0')) !== 0)));
            if ($financialChange && auth()->user() instanceof User && ! auth()->user()->hasPermission('inventory.costs.approve')) {
                abort(403, 'Submit financial price and cost changes for Finance review.');
            }
            $this->assertSellingPrices($data, $locked);
            $activated = ! $locked->is_active && ($data['is_active'] ?? false);
            $locked->update($data);
            if ($activated) {
                app(FinishedGoodInventoryService::class)->ensureItem($locked);
            }
            if ($locked->wasChanged(['base_selling_price', 'minimum_selling_price', 'standard_cost'])) {
                app(PricingReviewService::class)->request([(int) $locked->id], 'product_prices_changed');
            }
            if ($customers !== null) {
                $locked->portalCustomers()->sync($customers);
            }

            return $locked->fresh()->load('portalCustomers:id,name');
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->assertCanArchive($locked);
            $locked->delete();
        });
    }

    public function restore(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $locked = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            if ($locked->trashed()) {
                $locked->restore();
            }

            return $locked->fresh();
        });
    }

    private function assertSellingPrices(array $data, ?Product $existing = null): void
    {
        $base = array_key_exists('base_selling_price', $data) ? $data['base_selling_price'] : $existing?->base_selling_price;
        $floor = array_key_exists('minimum_selling_price', $data) ? $data['minimum_selling_price'] : $existing?->minimum_selling_price;
        foreach (['base_selling_price' => $base, 'minimum_selling_price' => $floor] as $field => $value) {
            if ($value !== null && (! preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $value) || Money::lte((string) $value, '0'))) {
                throw ValidationException::withMessages([$field => ['Selling prices must be positive amounts with up to two decimal places.']]);
            }
        }
        if ($base !== null && $floor !== null && Money::lt($base, $floor)) {
            throw ValidationException::withMessages(['base_selling_price' => ['Base selling price cannot be below the minimum selling price.']]);
        }
    }

    private function hasBomSchema(): bool
    {
        return class_exists(Bom::class)
            && Schema::hasTable('bill_of_materials')
            && Schema::hasColumn('bill_of_materials', 'deleted_at');
    }

    private function canonicalizeUom(array $data): array
    {
        if (! isset($data['unit_of_measure'])) {
            return $data;
        }

        $uom = Uom::query()
            ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $data['unit_of_measure'])])
            ->lockForUpdate()
            ->first();
        if (! $uom) {
            throw new BusinessRuleException('The selected unit of measure is no longer available.');
        }

        $data['unit_of_measure'] = $uom->code;

        return $data;
    }

    private function hasInspectionSpecSchema(): bool
    {
        return class_exists(InspectionSpec::class)
            && Schema::hasTable('inspection_specs');
    }

    private function normalisePartNumber(array $data): array
    {
        if (isset($data['part_number'])) {
            $data['part_number'] = strtoupper(trim((string) $data['part_number']));
        }

        return $data;
    }

    private function assertUniquePartNumber(string $partNumber, ?int $exceptId = null): void
    {
        $query = DB::table('products')->whereRaw('UPPER(part_number) = ?', [$partNumber]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw new BusinessRuleException('A product with this part number already exists.');
        }
    }

    private function assertCanArchive(Product $product): void
    {
        $dependencies = [];
        if ($product->financial_change_request !== null) {
            $dependencies[] = 'a pending financial change';
        }
        if (PriceRequest::where('product_id', $product->id)->whereIn('status', ['requested', 'pending_finance', 'offered'])->exists()) {
            $dependencies[] = 'open pricing requests';
        }
        if ($product->salesOrderItems()->whereHas('salesOrder', fn ($query) => $query
            ->whereNotIn('status', [SalesOrderStatus::Cancelled->value, SalesOrderStatus::Closed->value]))
            ->exists()) {
            $dependencies[] = 'open sales orders';
        }
        if ($this->hasBomSchema() && Bom::query()
            ->where('product_id', $product->id)
            ->active()
            ->exists()) {
            $dependencies[] = 'an active BOM';
        }
        if ($product->priceAgreements()->exists()) {
            $dependencies[] = 'price agreements';
        }

        if ($dependencies !== []) {
            throw new BusinessRuleException(sprintf(
                'Cannot archive this product while it has %s. Archive or retire the dependent records first.',
                $this->joinDependencies($dependencies),
            ));
        }
    }

    public function proposeFinancialChange(Product $product, User $by, array $data): Product
    {
        abort_unless($by->hasPermission('crm.products.manage'), 403);

        return DB::transaction(function () use ($product, $by, $data) {
            $row = Product::lockForUpdate()->findOrFail($product->id);
            if (! $row->is_active || $row->financial_change_request !== null) {
                throw new BusinessRuleException('Product is inactive or already has a financial change awaiting review.');
            }
            $values = array_intersect_key($data, array_flip(['standard_cost', 'minimum_selling_price', 'base_selling_price']));
            $this->assertSellingPrices($values, $row);
            $row->forceFill(['financial_change_request' => ['id' => (string) Str::uuid(), 'values' => $values, 'notes' => $data['notes'], 'proposed_by' => $by->id, 'original' => $row->only(['standard_cost', 'minimum_selling_price', 'base_selling_price'])]])->save();
            $users = User::where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('inventory.costs.approve'));
            app(NotificationService::class)->sendInApp($users, 'pricing.review_required', ['title' => 'Financial price review: '.$row->part_number, 'message' => $data['notes'], 'link_to' => '/crm/products/'.$row->hash_id], 'pricing:financial:'.$row->financial_change_request['id']);

            return $this->show($row);
        });
    }

    public function decideFinancialChange(Product $product, User $by, array $data): Product
    {
        abort_unless($by->hasPermission('inventory.costs.approve'), 403);

        return DB::transaction(function () use ($product, $by, $data) {
            $row = Product::lockForUpdate()->findOrFail($product->id);
            $proposal = $row->financial_change_request;
            if (! $proposal || $proposal['id'] !== $data['proposal_id']) {
                throw new BusinessRuleException('Financial proposal changed. Refresh before reviewing.');
            }
            if ($proposal['proposed_by'] === $by->id) {
                abort(403, 'A different Finance reviewer must decide this proposal.');
            }
            if ($data['decision'] === 'approve') {
                $original = $proposal['original'];
                $current = $row->only(['standard_cost', 'minimum_selling_price', 'base_selling_price']);
                ksort($original);
                ksort($current);
                if ($original !== $current) {
                    throw new BusinessRuleException('Financial values changed after proposal. Reject and submit current values.');
                }
                $this->update($row, $proposal['values']);
            }
            $row->forceFill(['financial_change_request' => [...$proposal, 'decision' => $data['decision'], 'decision_notes' => $data['notes'], 'reviewed_by' => $by->hash_id, 'reviewed_at' => now()->toISOString()]])->save();
            $row->forceFill(['financial_change_request' => null])->save();
            // HasAuditLog preserves the proposal, decision actor and changed values.
            $user = User::find($proposal['proposed_by']);
            if ($user && $user->is_active) {
                app(NotificationService::class)->sendInApp($user, 'pricing.review_required', ['title' => 'Financial proposal '.$data['decision'].': '.$row->part_number, 'message' => $data['notes'], 'link_to' => '/crm/products/'.$row->hash_id], 'pricing:financial:'.$proposal['id'].':'.$data['decision']);
            }

            return $this->show($row->fresh());
        });
    }

    /** @param list<string> $dependencies */
    private function joinDependencies(array $dependencies): string
    {
        if (count($dependencies) === 1) {
            return $dependencies[0];
        }

        $last = array_pop($dependencies);

        return implode(', ', $dependencies).' and '.$last;
    }
}
