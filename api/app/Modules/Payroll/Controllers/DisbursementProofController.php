<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Payroll\Enums\DisbursementProofType;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Resources\DisbursementProofResource;
use App\Modules\Payroll\Services\DisbursementEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisbursementProofController extends Controller
{
    public function __construct(private readonly DisbursementEvidenceService $evidence) {}

    public function options(PayrollPeriod $period): JsonResponse
    {
        return response()->json(['data' => [
            'proof_types' => array_map(
                static fn (DisbursementProofType $type): array => ['value' => $type->value, 'label' => $type->label()],
                DisbursementProofType::cases(),
            ),
        ]]);
    }
    /**
     * List all disbursement proofs for a period.
     */
    public function index(PayrollPeriod $period): AnonymousResourceCollection
    {
        $proofs = $period->disbursementProofs()->with('uploader')->orderByDesc('created_at')->get();

        return DisbursementProofResource::collection($proofs);
    }

    /**
     * Upload a new disbursement proof file.
     */
    public function store(PayrollPeriod $period, Request $request): JsonResponse
    {
        $this->authorizeFinance($request);

        $validated = $request->validate([
            'proof_type' => ['required', Rule::enum(DisbursementProofType::class)],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'disbursed_amount' => ['required', 'decimal:0,2', 'gt:0'],
            'disbursement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('file');
        $dir = 'payroll-proofs';
        $disk = Storage::disk('local');
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $filename = sprintf(
            '%s_%s_%s.%s',
            str_replace('_', '-', $validated['proof_type']),
            now()->format('Ymd_His'),
            bin2hex(random_bytes(4)),
            $file->extension(),
        );
        $relative = $dir.DIRECTORY_SEPARATOR.$filename;
        if ($disk->putFileAs($dir, $file, $filename) === false) {
            throw new BusinessRuleException('Unable to store disbursement proof.');
        }

        try {
            $proof = DB::transaction(function () use ($period, $validated, $request, $relative): DisbursementProof {
                $lockedPeriod = PayrollPeriod::query()->lockForUpdate()->find($period->id);
                if (! $lockedPeriod) {
                    throw new BusinessRuleException('Payroll period not found.');
                }

                $this->evidence->assertCanUpload($lockedPeriod, (string) $validated['disbursed_amount']);

                $proof = DisbursementProof::create([
                    'payroll_period_id' => $lockedPeriod->id,
                    'proof_type' => $validated['proof_type'],
                    'file_name' => $request->file('file')->getClientOriginalName(),
                    'file_path' => $relative,
                    'bank_name' => $validated['bank_name'] ?? null,
                    'transaction_reference' => $validated['transaction_reference'] ?? null,
                    'disbursed_amount' => $validated['disbursed_amount'],
                    'disbursement_date' => $validated['disbursement_date'],
                    'uploaded_by' => $request->user()->id,
                    'notes' => $validated['notes'] ?? null,
                ]);

                $this->evidence->syncStatus($lockedPeriod->fresh());

                return $proof;
            });
        } catch (\Throwable $e) {
            $disk->delete($relative);
            throw $e;
        }

        $proof->load('uploader');

        return (new DisbursementProofResource($proof))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * View / download a proof file.
     * Inline for images (browser preview), attachment for PDFs.
     */
    public function show(PayrollPeriod $period, DisbursementProof $proof): StreamedResponse
    {
        if ($proof->payroll_period_id !== $period->id) {
            abort(404, 'Proof does not belong to this period.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($proof->file_path)) {
            abort(404, 'Proof file not found on disk.');
        }

        $mime = $disk->mimeType($proof->file_path) ?? 'application/octet-stream';
        $isImage = str_starts_with($mime, 'image/');

        return response()->stream(
            function () use ($disk, $proof) {
                $stream = $disk->readStream($proof->file_path);
                if (! $stream) {
                    abort(404);
                }
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Disposition' => $isImage
                    ? sprintf('inline; filename="%s"', $proof->file_name)
                    : sprintf('attachment; filename="%s"', $proof->file_name),
            ],
        );
    }

    /**
     * Archive a proof row (Finance only) while retaining its private artifact.
     * Cannot archive proofs once the period has been marked as disbursed.
     */
    public function destroy(PayrollPeriod $period, DisbursementProof $proof, Request $request): JsonResponse
    {
        $this->authorizeFinance($request);

        if ($proof->payroll_period_id !== $period->id) {
            abort(404, 'Proof does not belong to this period.');
        }

        DB::transaction(function () use ($period, $proof): void {
            $lockedPeriod = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (in_array($lockedPeriod->status, [PayrollPeriodStatus::Disbursed, PayrollPeriodStatus::Voided], true)) {
                throw new BusinessRuleException('Disbursement evidence cannot be archived after the period is closed.');
            }

            $lockedProof = DisbursementProof::query()->lockForUpdate()->findOrFail($proof->id);
            $lockedProof->delete();
            // Keep the private object when soft-deleting the row. Restore is an
            // advertised evidence-recovery action, so physical deletion here
            // would make the restored row point at a non-existent artifact.
            $this->evidence->syncStatus($lockedPeriod->fresh());
        });

        return response()->json(['message' => 'Proof deleted.']);
    }

    public function restore(PayrollPeriod $period, DisbursementProof $proof, Request $request): JsonResponse
    {
        $this->authorizeFinance($request);

        if ($proof->payroll_period_id !== $period->id) {
            abort(404, 'Proof does not belong to this period.');
        }
        if (! Storage::disk('local')->exists((string) $proof->file_path)) {
            throw new BusinessRuleException('The archived proof file is missing from private storage and cannot be restored.');
        }

        DB::transaction(function () use ($period, $proof): void {
            $lockedPeriod = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (in_array($lockedPeriod->status, [PayrollPeriodStatus::Disbursed, PayrollPeriodStatus::Voided], true)) {
                throw new BusinessRuleException('Disbursement evidence cannot be restored after the period is closed.');
            }

            $lockedProof = DisbursementProof::withTrashed()->lockForUpdate()->findOrFail($proof->id);
            $this->evidence->assertCanUpload($lockedPeriod, (string) $lockedProof->disbursed_amount);
            $lockedProof->restore();
            $this->evidence->syncStatus($lockedPeriod->fresh());
        });

        return response()->json(['message' => 'Proof restored.']);
    }

    private function authorizeFinance(Request $request): void
    {
        $user = $request->user();
        if (! $user?->can('payroll.periods.finalize')) {
            abort(403, 'Only Finance officers can manage disbursement proofs.');
        }
    }
}
