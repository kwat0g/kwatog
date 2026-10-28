<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\SystemUserResolver;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\PriceRequestStatus as Status;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Services\BomCostingService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PriceNegotiationService
{
    public function list(?int $customerId = null, ?string $requestHashId = null): LengthAwarePaginator
    {
        return PriceRequest::with(['product', 'customer'])
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->when($requestHashId !== null, fn ($q) => $q->whereHash($requestHashId))
            ->latest('id')->paginate(25);
    }

    public function request(CustomerPortalUser $by, array $data): PriceRequest
    {
        return app(SystemUserResolver::class)->impersonate(fn () => DB::transaction(function () use ($by, $data): PriceRequest {
            app(PriceAgreementService::class)->lockPricingReferences([(int) $data['product_id']], [$by->customer_id]);
            $product = Product::active()->findOrFail($data['product_id']);
            if (! $by->customer->is_active || ! $product->isAvailableToCustomer($by->customer_id)) {
                abort(404);
            }
            $this->assertNoOpen($by->customer_id, $product->id);
            $row = PriceRequest::create(['customer_id' => $by->customer_id, 'product_id' => $product->id, 'requested_by' => $by->id,
                'quantity' => $data['quantity'], 'requested_price' => $data['requested_price'] ?? null, 'notes' => $data['notes'], 'status' => Status::Requested]);
            $this->notice($row, 'Customer requested discount pricing.', ['sales_officer', 'customer_service_officer'], portalActor: $by);

            return $row->load(['product', 'customer']);
        }));
    }

    public function offer(?PriceRequest $request, User $by, array $data): PriceRequest
    {
        abort_unless($by->hasPermission('crm.price_agreements.propose'), 403);

        return DB::transaction(function () use ($request, $by, $data): PriceRequest {
            $productId = $request?->product_id ?? (int) $data['product_id'];
            $customerId = $request?->customer_id ?? (int) $data['customer_id'];
            app(PriceAgreementService::class)->lockPricingReferences([$productId], [$customerId]);
            $product = Product::active()->findOrFail($productId);
            if (! Customer::active()->whereKey($customerId)->exists()) {
                throw new BusinessRuleException('Customer is inactive.');
            }
            if (! $product->isAvailableToCustomer($customerId)) {
                throw new BusinessRuleException('Assign this restricted product to the customer before proposing prices.');
            }
            if ($request) {
                $row = PriceRequest::lockForUpdate()->findOrFail($request->id);
                if (! in_array($row->status, [Status::Requested, Status::PendingFinance, Status::Offered], true)) {
                    throw new BusinessRuleException('This negotiation is closed. Create a new proposal.');
                }
            } else {
                $this->assertNoOpen($customerId, $productId);
                $row = new PriceRequest(['customer_id' => $customerId, 'product_id' => $productId, 'notes' => $data['notes']]);
            }
            $offer = array_intersect_key($data, array_flip(['price', 'pricing_method', 'tiers', 'effective_from', 'effective_to', 'notes']));
            $offer['pricing_method'] ??= 'flat';
            $offer['tiers'] ??= null;
            $this->validateOffer($product, $offer);
            $offer['agreement_signature'] = $this->agreementSignature($customerId, $productId);
            $row->forceFill(['offer' => $offer, 'status' => Status::PendingFinance, 'version' => $row->version + 1,
                'proposed_by' => $by->id, 'reviewed_by' => null, 'reviewed_at' => null, 'cost_review_signature' => null]);
            $this->history($row, 'offer', $offer, $by->hash_id);
            $row->save();
            $this->notice($row, 'New price proposal requires Finance review. Customer terms have not changed.', ['finance_officer'], actor: $by);

            return $row->load(['product', 'customer']);
        }, 3);
    }

    public function approve(PriceRequest $request, User $by, array $data): PriceRequest
    {
        abort_unless($by->hasPermission('crm.price_agreements.approve'), 403);

        return DB::transaction(function () use ($request, $by, $data): PriceRequest {
            app(PriceAgreementService::class)->lockPricingReferences([$request->product_id], [$request->customer_id]);
            $row = PriceRequest::lockForUpdate()->findOrFail($request->id);
            if ($row->status !== Status::PendingFinance) {
                throw new BusinessRuleException('Only a proposal awaiting Finance review can be approved.');
            }
            if ($row->version !== (int) ($data['version'] ?? 0)) {
                throw new BusinessRuleException('Proposal changed. Refresh and review the latest version before approving.');
            }
            if ($row->proposed_by === $by->id) {
                abort(403, 'Proposal creators cannot approve their own terms.');
            }
            $product = Product::active()->findOrFail($row->product_id);
            $this->validateOffer($product, $row->offer, false);
            $cost = $this->reviewCost($product);
            if (! hash_equals($cost['signature'], (string) ($data['cost_signature'] ?? ''))) {
                throw new BusinessRuleException('Cost basis changed. Refresh and review the latest costs before approving.');
            }
            if ($cost['incomplete']) {
                throw new BusinessRuleException('Manufacturing cost is incomplete. PPC must configure routing, operation rates, time and component costs before Finance approval.');
            }
            if ($product->minimum_selling_price === null || Money::lt($product->minimum_selling_price, $cost['cost'])) {
                throw new BusinessRuleException('Configure a minimum selling price at least equal to the reviewed unit cost before approving.');
            }
            foreach ([$row->offer['price'], ...array_column($row->offer['tiers'] ?? [], 'unit_price')] as $price) {
                if (Money::lte($price, $cost['cost'])) {
                    throw new BusinessRuleException('Proposal prices must exceed the reviewed unit cost.');
                }
            }
            $row->forceFill(['status' => Status::Offered, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'cost_review_signature' => $cost['signature']]);
            $this->history($row, 'finance_approved', ['notes' => $data['notes']], $by->hash_id);
            $row->save();
            $this->notice($row, 'Finance approved this offer. Customer acceptance is required before applying it.', ['sales_officer', 'customer_service_officer'], actor: $by);

            return $row->load(['product', 'customer']);
        }, 3);
    }

    public function respond(PriceRequest $request, CustomerPortalUser $by, string $decision, int $version): PriceRequest
    {
        abort_unless((int) $request->customer_id === (int) $by->customer_id, 404);

        return app(SystemUserResolver::class)->impersonate(fn () => DB::transaction(function () use ($request, $by, $decision, $version): PriceRequest {
            app(PriceAgreementService::class)->lockPricingReferences([$request->product_id], [$by->customer_id]);
            $row = PriceRequest::lockForUpdate()->findOrFail($request->id);
            abort_unless($row->customer_id === $by->customer_id, 404);
            if ($row->version !== $version) {
                throw new BusinessRuleException('Offer changed. Refresh and review the latest terms.');
            }
            $target = $decision === 'accept' ? Status::Accepted : Status::Rejected;
            if ($row->status === $target) {
                return $row->load(['product', 'customer']);
            }
            if ($row->status !== Status::Offered) {
                throw new BusinessRuleException('This offer is not awaiting customer acceptance.');
            }
            if ($decision === 'accept') {
                $product = Product::active()->findOrFail($row->product_id);
                if (! $by->customer->is_active || ! $product->isAvailableToCustomer($by->customer_id)) {
                    throw new BusinessRuleException('Product or customer availability changed. Contact sales.');
                }
                $this->validateOffer($product, $row->offer, false);
                if ($this->reviewCost($product)['signature'] !== $row->cost_review_signature) {
                    throw new BusinessRuleException('Cost or price limits changed after Finance review. Sales must submit the offer for review again.');
                }
                $offer = $row->offer;
                // Pending offers may coexist with later agreement writes. Confirm
                // the customer still sees the same commercial state Finance reviewed.
                $currentSignature = $this->agreementSignature($row->customer_id, $row->product_id);
                if (($offer['agreement_signature'] ?? $currentSignature) !== $currentSignature) {
                    throw new BusinessRuleException('Agreement terms changed after this offer. Sales must revise the proposal before acceptance.');
                }
                $overlaps = PriceAgreement::where('customer_id', $row->customer_id)->where('product_id', $row->product_id)
                    ->whereDate('effective_from', '<=', $offer['effective_to'])->whereDate('effective_to', '>=', $offer['effective_from'])->lockForUpdate()->get();
                foreach ($overlaps as $old) {
                    if ($old->effective_from->toDateString() >= $offer['effective_from']) {
                        throw new BusinessRuleException('A current or scheduled agreement already starts on or after this date. Choose a later replacement date.');
                    }
                    $this->history($row, 'replaced_terms', ['agreement_id' => $old->hash_id, 'price' => $old->price, 'tiers' => $old->tiers, 'effective_from' => $old->effective_from->toDateString(), 'effective_to' => $old->effective_to->toDateString()], $by->hash_id);
                    $old->forceFill(['effective_to' => Carbon::parse($offer['effective_from'])->subDay()->toDateString()])->save();
                }
                $agreement = app(PriceAgreementService::class)->create(['customer_id' => $row->customer_id, 'product_id' => $row->product_id,
                    ...array_intersect_key($offer, array_flip(['price', 'pricing_method', 'tiers', 'effective_from', 'effective_to']))]);
                $row->agreement_id = $agreement->id;
            }
            $row->status = $target;
            $row->responded_at = now();
            $this->history($row, $decision, ['version' => $version], $by->hash_id);
            $row->save();
            $this->notice($row, $decision === 'accept' ? 'Customer accepted the reviewed terms. Dated prices now apply to new orders.' : 'Customer declined the offer. Existing pricing remains.', ['sales_officer', 'customer_service_officer', 'finance_officer'], portalActor: $by);

            return $row->load(['product', 'customer']);
        }, 3));
    }

    public function withdraw(PriceRequest $request, User $by, string $notes): PriceRequest
    {
        abort_unless($by->hasPermission('crm.price_agreements.propose') || $by->hasPermission('crm.price_agreements.approve'), 403);

        return DB::transaction(function () use ($request, $by, $notes): PriceRequest {
            $row = PriceRequest::lockForUpdate()->findOrFail($request->id);
            if (! in_array($row->status, [Status::Requested, Status::PendingFinance, Status::Offered], true)) {
                throw new BusinessRuleException('This negotiation is already closed.');
            }
            $row->status = Status::Withdrawn;
            $this->history($row, 'withdrawn', ['notes' => $notes], $by->hash_id);
            $row->save();
            $this->notice($row, 'Pricing proposal withdrawn: '.$notes, ['sales_officer', 'customer_service_officer', 'finance_officer'], actor: $by);

            return $row->load(['product', 'customer']);
        });
    }

    public function expire(): void
    {
        PriceRequest::whereIn('status', ['pending_finance', 'offered'])->get()->each(function ($row): void {
            if (($row->offer['effective_to'] ?? '') < today()->toDateString()) {
                DB::transaction(function () use ($row): void {
                    $row = PriceRequest::lockForUpdate()->findOrFail($row->id);
                    if (in_array($row->status, [Status::PendingFinance, Status::Offered], true) && $row->offer['effective_to'] < today()->toDateString()) {
                        $row->status = Status::Expired;
                        $this->history($row, 'expired', ['notes' => 'The offer expired before customer acceptance. Contact Sales for new terms.'], app(SystemUserResolver::class)->user()->hash_id);
                        $row->save();
                        $this->notice($row, 'Pricing offer expired before customer acceptance.', ['sales_officer', 'customer_service_officer', 'finance_officer']);
                    }
                });
            }
        });
    }

    private function assertNoOpen(int $customerId, int $productId): void
    {
        if (PriceRequest::where('customer_id', $customerId)->where('product_id', $productId)->whereIn('status', ['requested', 'pending_finance', 'offered'])->exists()) {
            throw new BusinessRuleException('An open pricing request already exists for this customer and product.');
        }
    }

    private function validateOffer(Product $product, array $offer, bool $proposing = true): void
    {
        if (($proposing && $offer['effective_from'] < today()->toDateString()) || $offer['effective_to'] < $offer['effective_from'] || $offer['effective_to'] < today()->toDateString()) {
            throw new BusinessRuleException('Proposal dates must be valid and the offer must not be expired.');
        }
        app(PriceAgreementService::class)->assertPriceFloor($product, (string) $offer['price'], 'price');
        if ($offer['pricing_method'] === 'flat' && ! empty($offer['tiers'])) {
            throw ValidationException::withMessages(['tiers' => 'Flat prices cannot contain quantity tiers.']);
        }
        $previous = 0;
        foreach ($offer['tiers'] ?? [] as $index => $tier) {
            if ((int) $tier['min_qty'] <= $previous) {
                throw ValidationException::withMessages(['tiers' => 'Quantity thresholds must be strictly increasing.']);
            }
            $previous = (int) $tier['min_qty'];
            app(PriceAgreementService::class)->assertPriceFloor($product, (string) $tier['unit_price'], "tiers.{$index}.unit_price");
        }
        if (($offer['pricing_method'] === 'tiered') && empty($offer['tiers'])) {
            throw ValidationException::withMessages(['tiers' => 'Add at least one quantity tier.']);
        }
    }

    private function agreementSignature(int $customerId, int $productId): string
    {
        return hash('sha256', json_encode(PriceAgreement::where('customer_id', $customerId)->where('product_id', $productId)->orderBy('id')->get(['id', 'price', 'tiers', 'effective_from', 'effective_to'])->toArray(), JSON_THROW_ON_ERROR));
    }

    public function reviewCost(Product $product): array
    {
        $product->load('activeBom');
        if ($product->activeBom) {
            app(BomCostingService::class)->ensureFresh($product->activeBom);
        }
        $product->refresh()->load(['activeBom', 'inventoryItem']);
        $bom = $product->activeBom;
        $item = $product->inventoryItem;
        $cost = Money::gt($bom?->total_cost ?? '0', $product->standard_cost) ? $bom->total_cost : $product->standard_cost;
        if ($item && Money::gt($item->standard_cost, $cost)) {
            $cost = Money::round2($item->standard_cost);
        }
        $manufactured = $bom || Bom::withTrashed()->where('product_id', $product->id)->exists()
            || DB::table('product_routings')->where('product_id', $product->id)->exists()
            || DB::table('work_orders')->where('product_id', $product->id)->exists();
        $incomplete = Money::lte($cost, '0') || ($item && ! $product->finished_good_readiness['ready'])
            || ($manufactured && ! $bom) || ($bom && ($bom->costed_at === null || ! empty($bom->cost_warnings)));

        return ['cost' => $cost, 'incomplete' => (bool) $incomplete, 'signature' => hash('sha256', json_encode([$cost, $incomplete, $product->base_selling_price, $product->minimum_selling_price, $bom?->id, $bom?->cost_warnings, $item?->id, $item?->standard_cost], JSON_THROW_ON_ERROR))];
    }

    private function history(PriceRequest $row, string $action, array $data, string $actor): void
    {
        $row->history = [...($row->history ?? []), ['action' => $action, 'actor' => $actor, 'at' => now()->toISOString(), 'data' => $data]];
    }

    private function notice(PriceRequest $row, string $message, array $roles, ?User $actor = null, ?CustomerPortalUser $portalActor = null): void
    {
        $permissions = array_map(fn ($role) => $role === 'finance_officer' ? 'crm.price_agreements.approve' : 'crm.price_agreements.propose', $roles);
        $users = User::where('is_active', true)->get()->filter(fn ($user) => $user->id !== $actor?->id && collect($permissions)->contains(fn ($permission) => $user->hasPermission($permission)));
        app(NotificationService::class)->sendForSubject($row, $users, 'pricing.negotiation', ['title' => 'Pricing request: '.Product::findOrFail($row->product_id)->part_number, 'message' => $message, 'link_to' => '/crm/price-agreements/requests?request='.$row->hash_id], 'pricing:negotiation:'.$row->id.':'.$row->version.':'.$row->status->value, emailEnabled: false);
        if (in_array($row->status, [Status::Offered, Status::Accepted, Status::Rejected, Status::Withdrawn, Status::Expired], true)) {
            $portalRecipients = CustomerPortalUser::where('customer_id', $row->customer_id)->where('is_active', true)
                ->when($portalActor, fn ($query) => $query->whereKeyNot($portalActor->id))
                ->get();
            app(NotificationService::class)->sendPortalInApp($portalRecipients, 'pricing.negotiation', [
                'title' => 'Pricing update: '.Product::findOrFail($row->product_id)->part_number,
                'message' => $message, 'link_to' => '/portal/customer/pricing?request='.$row->hash_id, 'entity_type' => 'price_request', 'entity_id' => $row->hash_id,
            ], 'pricing:'.$row->id.':'.$row->version.':'.$row->status->value);
        }
    }
}
