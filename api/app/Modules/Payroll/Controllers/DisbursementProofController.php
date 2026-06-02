<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Resources\DisbursementProofResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisbursementProofController
{
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
            'proof_type'            => ['required', 'string', Rule::in(['deposit_slip', 'bank_confirmation', 'transfer_receipt', 'other'])],
            'file'                  => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'bank_name'             => ['nullable', 'string', 'max:100'],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'disbursed_amount'      => ['nullable', 'numeric', 'min:0'],
            'disbursement_date'     => ['required', 'date'],
            'notes'                 => ['nullable', 'string', 'max:500'],
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
        $relative = $dir . DIRECTORY_SEPARATOR . $filename;
        $disk->putFileAs($dir, $file, $filename);

        $proof = DisbursementProof::create([
            'payroll_period_id'     => $period->id,
            'proof_type'            => $validated['proof_type'],
            'file_name'             => $file->getClientOriginalName(),
            'file_path'             => $relative,
            'bank_name'             => $validated['bank_name'] ?? null,
            'transaction_reference' => $validated['transaction_reference'] ?? null,
            'disbursed_amount'      => $validated['disbursed_amount'] ?? null,
            'disbursement_date'     => $validated['disbursement_date'],
            'uploaded_by'           => $request->user()->id,
            'notes'                 => $validated['notes'] ?? null,
        ]);

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
            throw new RuntimeException('Proof does not belong to this period.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($proof->file_path)) {
            throw new RuntimeException('Proof file not found on disk.');
        }

        $contents = $disk->get($proof->file_path);
        $mime = $disk->mimeType($proof->file_path) ?? 'application/octet-stream';
        $isImage = str_starts_with($mime, 'image/');

        return response()->stream(
            fn () => print $contents,
            200,
            [
                'Content-Type'        => $mime,
                'Cache-Control'       => 'private, no-store, max-age=0',
                'Content-Disposition' => $isImage
                    ? sprintf('inline; filename="%s"', $proof->file_name)
                    : sprintf('attachment; filename="%s"', $proof->file_name),
            ],
        );
    }

    /**
     * Delete a proof file (Finance only).
     * Cannot delete proofs once the period has been marked as disbursed.
     */
    public function destroy(PayrollPeriod $period, DisbursementProof $proof, Request $request): JsonResponse
    {
        $this->authorizeFinance($request);

        if ($proof->payroll_period_id !== $period->id) {
            throw new RuntimeException('Proof does not belong to this period.');
        }

        if ($period->status === 'disbursed') {
            return response()->json(['message' => 'Cannot delete proof after the period has been marked as disbursed.'], 422);
        }

        Storage::disk('local')->delete($proof->file_path);
        $proof->delete();

        return response()->json(['message' => 'Proof deleted.']);
    }

    private function authorizeFinance(Request $request): void
    {
        $user = $request->user();
        if (! $user?->can('payroll.periods.finalize')) {
            abort(403, 'Only Finance officers can manage disbursement proofs.');
        }
    }
}
