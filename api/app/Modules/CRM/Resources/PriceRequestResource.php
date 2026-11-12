<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Modules\Auth\Models\User;

class PriceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $portal = str_starts_with($request->path(), 'api/v1/b2b/customer/');
        $status = $this->status->value;
        $open = in_array($status, ['requested', 'pending_finance', 'offered'], true);
        $portalUser = $portal ? $request->user('customer_portal') : null;
        $internalUser = $portal ? null : $request->user();
        $canPropose = $internalUser instanceof User
            && $internalUser->hasPermission('crm.price_agreements.propose')
            && $open;
        $canApprove = $internalUser instanceof User
            && $internalUser->hasPermission('crm.price_agreements.approve')
            && $status === 'pending_finance'
            && (int) $this->proposed_by !== (int) $internalUser->id;
        $canWithdraw = $internalUser instanceof User
            && $open
            && $this->requested_by === null
            && ($internalUser->hasPermission('crm.price_agreements.propose') || $internalUser->hasPermission('crm.price_agreements.approve'));
        $canRejectBySales = $internalUser instanceof User
            && $internalUser->hasPermission('crm.price_agreements.propose')
            && $this->requested_by !== null
            && $status === 'requested';
        $canCancel = $portalUser !== null
            && (int) $portalUser->customer_id === (int) $this->customer_id
            && $this->requested_by !== null
            && in_array($status, ['requested', 'pending_finance'], true);
        $canDecide = $portalUser !== null
            && (int) $portalUser->customer_id === (int) $this->customer_id
            && $status === 'offered';
        $limitIncreaseRequest = collect($this->history ?? [])->firstWhere('action', 'order_limit_increase_requested');

        return ['id' => $this->hash_id, 'product' => ['id' => $this->product->hash_id, 'part_number' => $this->product->part_number, 'name' => $this->product->name, 'unit_of_measure' => $this->product->unit_of_measure],
            'customer' => ['id' => $this->customer->hash_id, 'name' => $this->customer->name], 'quantity' => $this->quantity, 'requested_price' => $this->requested_price,
            'order_limit_increase_request' => $limitIncreaseRequest ? [
                'current_maximum_quantity' => (string) $limitIncreaseRequest['data']['current_maximum_quantity'],
                'requested_quantity' => (string) $limitIncreaseRequest['data']['requested_quantity'],
            ] : null,
            'notes' => $this->notes, 'status' => $status, 'version' => $this->version,
            'permissions' => [
                'view' => $portal
                    ? (int) $portalUser?->customer_id === (int) $this->customer_id
                    : $internalUser instanceof User && collect(['crm.price_agreements.view', 'crm.price_agreements.approve', 'crm.price_agreements.propose'])->contains(fn (string $permission): bool => $internalUser->hasPermission($permission)),
                'propose' => $canPropose,
                'approve' => $canApprove,
                'withdraw' => $canWithdraw,
                'reject' => $canRejectBySales || ($canDecide && $status === 'offered'),
                'cancel' => $canCancel,
            ],
            'available_actions' => [
                'can_propose' => $canPropose,
                'can_approve' => $canApprove,
                'can_withdraw' => $canWithdraw,
                'can_reject' => $canRejectBySales || ($canDecide && $status === 'offered'),
                'can_cancel' => $canCancel,
                'can_accept' => $canDecide,
            ],
            'offer' => $this->when(! $portal || in_array($this->status->value, ['offered', 'accepted', 'rejected'], true), $this->offer ? array_diff_key($this->offer, ['agreement_signature' => true]) : null),
            'cost_review' => $this->when(! $portal && isset($this->cost_review), $this->cost_review),
            'decision_reason' => collect($this->history ?? [])->last(fn ($entry) => in_array($entry['action'], ['withdrawn', 'expired', 'sales_rejected', 'customer_cancelled'], true))['data']['notes'] ?? null,
            'history' => $this->when(! $portal, $this->history), 'created_at' => $this->created_at?->toISOString()];
    }
}
