<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAdvance;
use App\Modules\CRM\Requests\ConfigureSalesOrderAdvanceRequest;
use App\Modules\CRM\Requests\DecideSalesOrderAdvanceRequest;
use App\Modules\CRM\Resources\SalesOrderAdvanceResource;
use App\Modules\CRM\Services\SalesOrderAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesOrderAdvanceController
{
    public function __construct(private readonly SalesOrderAdvanceService $service) {}

    public function configure(ConfigureSalesOrderAdvanceRequest $request, SalesOrder $salesOrder): SalesOrderAdvanceResource|JsonResponse
    {
        $advance = $this->service->configure(
            $salesOrder,
            $request->validated('down_payment_amount'),
            $request->validated('down_payment_percentage'),
            $request->user(),
        );

        return $advance ? new SalesOrderAdvanceResource($advance->load(['salesOrder', 'customer'])) : response()->json(['data' => null]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = SalesOrderAdvance::query()->with([
            'salesOrder:id,so_number', 'customer:id,name', 'applications:id,sales_order_advance_id,amount',
        ]);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        $rows = $query->orderByDesc('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return SalesOrderAdvanceResource::collection($rows)->response();
    }

    public function downloadProof(SalesOrderAdvance $advance)
    {
        $this->service->assertProof($advance);

        return response()->file(Storage::disk('local')->path($advance->proof_path), [
            'Content-Disposition' => 'inline; filename="'.addslashes($advance->proof_file_name ?: 'payment-proof').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verify(DecideSalesOrderAdvanceRequest $request, SalesOrderAdvance $advance): SalesOrderAdvanceResource
    {
        $bank = $request->validated('bank_account_id');
        if (! is_string($bank) || $bank === '') {
            throw ValidationException::withMessages(['bank_account_id' => ['Select the account that received this bank transfer.']]);
        }

        return new SalesOrderAdvanceResource($this->service->verify($advance, $bank, $request->user()));
    }

    public function reject(DecideSalesOrderAdvanceRequest $request, SalesOrderAdvance $advance): SalesOrderAdvanceResource
    {
        $reason = trim((string) $request->validated('reason'));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Enter why Finance rejected this proof.']]);
        }

        return new SalesOrderAdvanceResource($this->service->reject($advance, $reason, $request->user()));
    }

    public function waive(DecideSalesOrderAdvanceRequest $request, SalesOrderAdvance $advance): SalesOrderAdvanceResource
    {
        $reason = trim((string) $request->validated('reason'));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Enter why Finance waived this down payment.']]);
        }

        return new SalesOrderAdvanceResource($this->service->waive($advance, $reason, $request->user()));
    }

    public function refund(DecideSalesOrderAdvanceRequest $request, SalesOrderAdvance $advance): SalesOrderAdvanceResource
    {
        $data = $request->validated();
        if (! is_string($data['bank_account_id'] ?? null) || trim($data['bank_account_id']) === '') {
            throw ValidationException::withMessages(['bank_account_id' => ['Select the account used to pay the refund.']]);
        }
        if (! is_string($data['reference_number'] ?? null) || trim($data['reference_number']) === '') {
            throw ValidationException::withMessages(['reference_number' => ['Enter the refund reference number.']]);
        }
        if (! is_string($data['reason'] ?? null) || trim($data['reason']) === '') {
            throw ValidationException::withMessages(['reason' => ['Enter why the unused advance is being refunded.']]);
        }

        return new SalesOrderAdvanceResource($this->service->refundUnapplied(
            $advance, $data['bank_account_id'], $data['reference_number'], $data['reason'], $request->user(),
        ));
    }
}
