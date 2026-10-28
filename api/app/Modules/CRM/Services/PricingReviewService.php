<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Support\Money;
use App\Common\Support\OutboxDispatchContext;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Services\ItemCostReviewService;
use App\Modules\MRP\Events\BomPricingReviewRequested;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Services\BomCostingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PricingReviewService
{
    private ?Collection $activeUsers = null;

    public function request(array $productIds, string $reason): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return;
        }
        app(OutboxService::class)->record(new BomPricingReviewRequested($productIds, $reason), 'pricing:review:'.Str::uuid());
    }

    public function handle(BomPricingReviewRequested $event): void
    {
        foreach ($event->productIds as $id) {
            DB::transaction(function () use ($id, $event): void {
                $product = Product::query()->lockForUpdate()->find($id);
                if (! $product || ! $product->is_active) {
                    return;
                }
                $bom = Bom::query()->active()->where('product_id', $id)->first();
                if ($bom) {
                    $wasStale = $bom->costed_at === null;
                    $previousCostedAt = $bom->costed_at?->toISOString();
                    try {
                        // Nested transaction rolls partial line updates back if costing refuses.
                        $bom = app(BomCostingService::class)->ensureFresh($bom);
                        if ($wasStale || $previousCostedAt !== $bom->costed_at?->toISOString() || in_array($event->reason, ['component_cost_or_conversion_changed', 'bom_or_routing_changed'], true)) {
                            $key = 'bom:cost:'.$bom->id.':'.(OutboxDispatchContext::payload()['outbox_id'] ?? today()->toDateString());
                            $this->notify('bom.cost_updated', "BOM refreshed: {$product->part_number}",
                                "Current unit estimate: {$bom->total_cost}. Review cost warnings and selling prices.",
                                '/mrp/boms/'.$bom->hash_id, $key, ['ppc_head', 'system_admin'], $bom);
                        }
                    } catch (BusinessRuleException $error) {
                        $bom->forceFill(['costed_at' => null])->save();
                        $this->notify('bom.cost_review_failed', "BOM refresh needs review: {$product->part_number}",
                            $error->getMessage(), '/mrp/boms/'.$bom->hash_id,
                            'bom:failed:'.$bom->id.':'.hash('sha256', $error->getMessage()).':'.today()->toDateString(), ['ppc_head', 'system_admin'], $bom);
                    }
                }
                if ($event->reason === 'product_prices_changed') {
                    $this->notify('pricing.prices_changed', "Selling prices changed: {$product->part_number}",
                        'Current base price: '.($product->base_selling_price ?? 'unset').'; minimum: '.($product->minimum_selling_price ?? 'unset').'. Customer agreement prices and existing orders were not changed. Review affected terms.',
                        '/crm/products/'.$product->hash_id, 'pricing:changed:'.$id.':'.(OutboxDispatchContext::payload()['outbox_id'] ?? Str::uuid()),
                        ['ppc_head', 'finance_officer', 'sales_officer', 'customer_service_officer', 'system_admin'], $product);
                }
                $this->reviewProduct($product->fresh()->load('activeBom'));
            }, 3);
        }
    }

    /** Internal-only warnings. Do not expose manufacturing costs in the customer portal. */
    public function risks(Product $product, ?PriceAgreement $agreement = null): array
    {
        $bom = $product->relationLoaded('activeBom') ? $product->activeBom : null;
        $product->loadMissing('inventoryItem');
        $cost = (string) $product->standard_cost;
        if ($product->inventoryItem && Money::gt($product->inventoryItem->standard_cost, $cost)) {
            $cost = (string) $product->inventoryItem->standard_cost;
        }
        if ($bom && $bom->costed_at !== null && Money::gt($bom->total_cost ?? '0', $cost)) {
            $cost = (string) $bom->total_cost;
        }
        $warnings = [];
        if ($bom && ($bom->costed_at === null || count($bom->cost_warnings ?? []) > 0)) {
            $warnings[] = 'Manufacturing cost is incomplete or awaiting refresh; profitability needs review.';
        }
        if ($product->minimum_selling_price === null) {
            $warnings[] = 'No minimum selling price configured.';
        } elseif (Money::lt($product->minimum_selling_price, $cost)) {
            $warnings[] = 'Minimum selling price is below a configured unit cost.';
        }
        $prices = $agreement ? ['Agreement price' => $agreement->price] : ['Base price' => $product->base_selling_price];
        if ($agreement?->pricing_method === PricingMethod::Tiered) {
            foreach ($agreement->tiers ?? [] as $tier) {
                $prices['Tier '.$tier['min_qty'].'+'] = $tier['unit_price'];
            }
            if ($product->base_selling_price !== null && Money::cmp($agreement->price, $product->base_selling_price) !== 0) {
                $warnings[] = 'Saved fallback price differs from current base price; review pricing below the first tier.';
            }
        }
        foreach ($prices as $label => $price) {
            if ($price === null) {
                $warnings[] = $label.' is not configured.';

                continue;
            }
            if ($product->minimum_selling_price !== null && Money::lt($price, $product->minimum_selling_price)) {
                $warnings[] = $label.' is below the minimum selling price; new quotes at this price are blocked.';
            }
            if (Money::lt($price, $cost)) {
                $warnings[] = $label.' is below a configured unit cost.';
            }
        }

        return $warnings;
    }

    public function reviewProduct(Product $product): void
    {
        $agreements = PriceAgreement::with('customer')->whereHas('customer', fn ($customer) => $customer->where('is_active', true))->where('product_id', $product->id)->whereDate('effective_to', '>=', today())->get();
        $productWarnings = $this->risks($product);
        if ($productWarnings !== []) {
            $this->sendRisk($product, null, $productWarnings);
        }
        foreach ($agreements as $agreement) {
            $warnings = $this->risks($product, $agreement);
            if ($warnings !== []) {
                $this->sendRisk($product, $agreement, $warnings);
            }
        }
    }

    public function receiptCostChanged(GoodsReceiptNoteAccepted $event): void
    {
        $grn = $event->grn->fresh(['items.item']);
        if (! $grn || ! $grn->status->isBillable()) {
            return;
        }
        foreach ($grn->items as $line) {
            $item = $line->item;
            $receiptCost = bcadd((string) $line->unit_cost, (string) ($line->landed_cost_unit ?? '0'), 4);
            if (! $item || bccomp((string) $line->quantity_accepted, '0', 3) <= 0
                || bccomp($receiptCost, (string) $item->standard_cost, 4) === 0) {
                continue;
            }
            $review = app(ItemCostReviewService::class)->capture($line, $receiptCost, notify: false);
            $this->notify('inventory.receipt_cost_changed', "Supplier cost needs review: {$item->code}",
                "{$grn->grn_number}: accepted unit cost {$receiptCost} per {$item->unit_of_measure}; inventory standard cost {$item->standard_cost}. Review the approved standard cost. BOM estimates refresh when that standard changes.",
                '/crm/price-agreements/cost-reviews?review='.$review->hash_id,
                'pricing:receipt:'.$line->id.':'.$receiptCost.':'.$item->standard_cost,
                ['purchasing_officer', 'finance_officer', 'ppc_head', 'warehouse_staff', 'system_admin'],
                $review);
        }
    }

    public function reminders(): void
    {
        PriceAgreement::query()->with(['product', 'customer'])->whereHas('customer', fn ($customer) => $customer->where('is_active', true))->whereDate('effective_from', '<=', today())
            ->whereDate('effective_to', '>=', today())->whereDate('effective_to', '<=', today()->addDays(7))
            ->chunkById(100, function ($agreements): void {
                foreach ($agreements as $agreement) {
                    if (! $agreement->product?->is_active) {
                        continue;
                    }
                    $days = (int) today()->diffInDays($agreement->effective_to, false);
                    $this->notify('pricing.agreement_expiring', "Price agreement ending: {$agreement->product->part_number} / {$agreement->customer->name}",
                        "Agreement expires {$agreement->effective_to->toDateString()}. Renew terms with the customer. Afterwards, base pricing applies if configured; otherwise ordering is unavailable.",
                        '/crm/price-agreements?agreement='.$agreement->hash_id, 'pricing:expiry:'.$agreement->id.':'.$agreement->effective_to->toDateString().':'.($days <= 1 ? 'last_day' : 'week'),
                        ['sales_officer', 'customer_service_officer', 'system_admin'], $agreement);
                }
            });
    }

    private function sendRisk(Product $product, ?PriceAgreement $agreement, array $warnings): void
    {
        $bom = $product->relationLoaded('activeBom') ? $product->activeBom : null;
        $state = [$product->base_selling_price, $product->minimum_selling_price, $product->standard_cost,
            $bom?->total_cost, $warnings, $agreement?->price, $agreement?->tiers, $agreement?->effective_from?->toDateString(), $agreement?->effective_to?->toDateString()];
        $key = 'pricing:risk:'.$product->id.':'.($agreement?->id ?? 'base').':'.hash('sha256', json_encode($state));
        $subject = $agreement ?? $product;
        $link = $agreement
            ? '/crm/price-agreements?agreement='.$agreement->hash_id
            : '/crm/products/'.$product->hash_id;
        $this->notify('pricing.review_required', "Pricing review: {$product->part_number}",
            ($agreement ? 'Agreement for '.($agreement->relationLoaded('customer') ? $agreement->customer?->name : $agreement->hash_id).'. ' : 'Product pricing. ').implode(' ', $warnings),
            $link, $key, ['finance_officer', 'sales_officer', 'customer_service_officer', 'system_admin'], $subject);
    }

    private function notify(
        string $type,
        string $title,
        string $message,
        string $link,
        string $key,
        array $roles,
        Model $subject,
    ): void {
        $permission = match (true) {
            $subject instanceof ItemCostReview => 'inventory.costs.review',
            $subject instanceof Bom => 'mrp.boms.view',
            $subject instanceof PriceAgreement => 'crm.price_agreements.view',
            default => null,
        };
        $users = $this->activeUsers ??= User::query()->where('is_active', true)->get();
        $roleSlugs = array_fill_keys($roles, true);
        $audience = $users->filter(fn (User $user): bool => ($permission === null || $user->hasPermission($permission))
            && ($roleSlugs === [] || isset($roleSlugs[$user->role?->slug])));
        $data = ['title' => $title, 'message' => $message, 'link_to' => $link];
        app(NotificationService::class)->sendForSubject($subject, $audience, $type, $data, $key, emailEnabled: false);
    }

    public function notifyCostReview(ItemCostReview $review, string $message): void
    {
        $this->notify('inventory.receipt_cost_changed', 'Supplier cost review: '.$review->item->code, $message,
            '/crm/price-agreements/cost-reviews?review='.$review->hash_id, 'pricing:cost-review:'.$review->id.':'.$review->version, [], $review);
    }
}
