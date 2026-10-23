<?php

declare(strict_types=1);

namespace App\Modules\DSS\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $sourcePermissions = [
            'work_order' => 'production.work_orders.view',
            'ncr' => 'quality.ncr.view',
            'sales_order' => 'crm.sales_orders.view',
            'bill' => 'accounting.bills.view',
            'material_lot' => 'quality.inspections.view',
        ];
        $sourceReferences = is_array($this->source_references)
            ? array_values(array_filter($this->source_references, static function ($reference) use ($sourcePermissions, $user): bool {
                if (! is_array($reference) || ! is_string($reference['source_kind'] ?? null)) {
                    return false;
                }

                $permission = $sourcePermissions[$reference['source_kind']] ?? null;
                $publicId = $reference['public_id'] ?? null;
                $display = $reference['display_reference'] ?? null;
                $destination = $reference['destination'] ?? null;

                return $permission !== null
                    && $user?->hasPermission($permission) === true
                    && is_string($publicId) && strlen($publicId) <= 100
                    && preg_match('/^[A-Za-z0-9_-]+$/D', $publicId) === 1 && ! ctype_digit($publicId)
                    && is_string($display) && $display !== '' && trim($display) === $display && strlen($display) <= 120
                    && is_string($destination) && strlen($destination) <= 255
                    && preg_match('~^/(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)?$~D', $destination) === 1;
            }))
            : null;

        return [
            'id' => $this->hash_id,
            'detector_id' => $this->detector_id,
            'detector_version' => $this->detector_version,
            'domain' => $this->domain,
            'type' => $this->type,
            'severity' => $this->severity,
            'status' => $this->status,
            'owner_role_slugs' => is_array($this->owner_role_slugs) && array_is_list($this->owner_role_slugs)
                ? array_values(array_filter($this->owner_role_slugs, 'is_string'))
                : null,
            // Decimals arrive as strings — never reintroduce float rounding.
            'priority_score' => $this->priority_score,
            'impact_estimate' => $this->impact_estimate,
            'impact_unit' => $this->impact_unit,
            'impact_currency' => $this->impact_currency,
            'impact_metric' => $this->impact_metric,
            'impact_is_estimate' => $this->impact_is_estimate,
            'confidence' => $this->confidence,
            'data_sufficiency' => $this->data_sufficiency,
            'rationale' => $this->rationale,
            'action' => $this->action,
            'subject_type' => null,
            'decision_code' => $this->decision_code,
            'policy_version' => $this->policy_version,
            'source_references' => $sourceReferences,
            'first_detected_at' => $this->first_detected_at?->toISOString(),
            'last_evaluated_at' => $this->last_evaluated_at?->toISOString(),
            'source_observed_at' => $this->source_observed_at?->toISOString(),
            'business_due_at' => $this->business_due_at?->toISOString(),
            'verification_status' => $this->verification_status,
            'resolution_evidence' => $this->resolutionEvidence($request),
            'expires_at' => $this->expires_at?->toISOString(),
            'snoozed_until' => $this->snoozed_until?->toISOString(),
            'dismiss_reason' => $this->dismiss_reason,
            'decided_at' => $this->decided_at?->toISOString(),
            'events_count' => $this->whenCounted('events'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /** @return array{condition: string, verification_method: string, proof: string, verified_at: string, run_reference: string}|null */
    private function resolutionEvidence(Request $request): ?array
    {
        if ($this->verification_status !== 'verified_clear' || ! is_array($this->resolution_evidence)) {
            return null;
        }

        $conditions = [
            'work_order_overdue' => 'Production deadline',
            'ncr_sla_breach' => 'Quality response target',
            'three_way_match_blocked' => 'Blocked bill review state',
        ];
        $conditionVerifiers = [
            'work_order_overdue' => 'work_order_status',
            'ncr_sla_breach' => 'ncr_response_sla',
            'three_way_match_blocked' => 'three_way_match_saved_state',
        ];
        $methods = [
            'work_order_status' => 'Work order status',
            'ncr_response_sla' => 'Quality response status',
            'three_way_match_saved_state' => 'Saved bill review state',
        ];
        $evidence = $this->resolution_evidence;
        $condition = $conditions[$evidence['source_condition'] ?? ''] ?? null;
        $method = $methods[$evidence['verifier'] ?? ''] ?? null;
        $permission = match ($evidence['verifier'] ?? null) {
            'work_order_status' => 'production.work_orders.view',
            'ncr_response_sla' => 'quality.ncr.view',
            'three_way_match_saved_state' => 'accounting.bills.view',
            default => null,
        };
        $proof = $evidence['proof'] ?? null;
        $verifiedAt = $evidence['observed_at'] ?? null;
        $runReference = $evidence['run_reference'] ?? null;

        if ($condition === null || $method === null
            || $conditionVerifiers[$evidence['source_condition'] ?? ''] !== ($evidence['verifier'] ?? null)
            || $permission === null || $request->user()?->hasPermission($permission) !== true
            || ! is_string($proof) || trim($proof) === '' || strlen($proof) > 500
            || preg_match('/[0-9\\\\]|\b(?:WorkOrder|NonConformanceReport|WorkOrderInterventionVerifier|NcrSlaVerifier)\b/', $proof) === 1
            || ! is_string($verifiedAt) || strtotime($verifiedAt) === false
            || ! is_string($runReference) || strlen($runReference) > 100
            || preg_match('/^[A-Za-z0-9_-]+$/D', $runReference) !== 1 || ctype_digit($runReference)) {
            return null;
        }

        return [
            'condition' => $condition,
            'verification_method' => $method,
            'proof' => $proof,
            'verified_at' => $verifiedAt,
            'run_reference' => $runReference,
        ];
    }
}
