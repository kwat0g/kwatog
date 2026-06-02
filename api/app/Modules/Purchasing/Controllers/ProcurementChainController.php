<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADV5 — Procurement Chain overview data.
 *
 * Returns aggregated counts across the entire procure-to-pay pipeline:
 * Material Requirements (PR/PO) → Receiving (GRN) → Billing (Bills).
 */
class ProcurementChainController extends Controller
{
    /** GET /api/v1/procurement/chain */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        \assert($user instanceof User);

        // ── Material Requirements ──
        $prCounts = Cache::remember('procurement_chain_pr', 60, fn () => [
            'pending_pr'   => PurchaseRequest::whereIn('status', ['draft', 'pending'])->count(),
            'approved_pr'  => PurchaseRequest::whereIn('status', ['approved', 'converted'])->count(),
        ]);

        $poCounts = Cache::remember('procurement_chain_po', 60, fn () => [
            'draft_po'              => PurchaseOrder::where('status', 'draft')->count(),
            'sent_po'               => PurchaseOrder::whereIn('status', ['approved', 'sent'])->count(),
            'partially_received_po' => PurchaseOrder::where('status', 'partially_received')->count(),
            'received_po'           => PurchaseOrder::where('status', 'received')->count(),
        ]);

        // ── Receiving ──
        $grnCounts = Cache::remember('procurement_chain_grn', 60, fn () => [
            'grn_received'  => GoodsReceiptNote::whereIn('status', ['accepted', 'partial'])->count(),
            'grn_pending_qc' => GoodsReceiptNote::where('status', 'pending_qc')->count(),
        ]);

        // ── Billing ──
        $billCounts = Cache::remember('procurement_chain_bills', 60, fn () => [
            'bills_unpaid'  => Bill::whereIn('status', ['unpaid', 'partial'])->count(),
            'bills_overdue' => Bill::whereIn('status', ['unpaid', 'partial'])
                ->where('due_date', '<', now())
                ->count(),
            'bills_this_month' => (string) Bill::whereIn('status', ['unpaid', 'partial', 'paid'])
                ->whereMonth('date', now()->month)
                ->whereYear('date', now()->year)
                ->sum(DB::raw('total_amount - COALESCE(amount_paid, 0)')),
        ]);

        // ── 3-way match stats ──
        $threeWayStats = Cache::remember('procurement_chain_three_way', 120, fn () => [
            'matched'          => Bill::where('has_variances', false)->where('status', '!=', 'cancelled')->count(),
            'has_variances'    => Bill::where('has_variances', true)->where('three_way_overridden', false)->count(),
            'overridden'       => Bill::where('three_way_overridden', true)->count(),
        ]);

        return response()->json([
            'data' => [
                'material_requirements' => [
                    'pr_pending'         => $prCounts['pending_pr'],
                    'pr_approved'        => $prCounts['approved_pr'],
                    'po_draft'           => $poCounts['draft_po'],
                    'po_sent'            => $poCounts['sent_po'],
                    'po_partially_received' => $poCounts['partially_received_po'],
                    'po_received'        => $poCounts['received_po'],
                ],
                'receiving' => [
                    'grn_received'      => $grnCounts['grn_received'],
                    'grn_pending_qc'    => $grnCounts['grn_pending_qc'],
                ],
                'billing' => [
                    'bills_unpaid'      => $billCounts['bills_unpaid'],
                    'bills_overdue'     => $billCounts['bills_overdue'],
                    'bills_this_month'  => $billCounts['bills_this_month'],
                ],
                'three_way_match' => $threeWayStats,
            ],
        ]);
    }
}
