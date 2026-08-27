<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Common\Enums\DocumentType;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Requests\StoreComplaintRequest;
use App\Modules\CRM\Resources\CustomerComplaintResource;
use App\Modules\CRM\Services\ComplaintService;
use App\Modules\Quality\Enums\NcrSeverity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplaintController
{
    public function __construct(
        private readonly ComplaintService $service,
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->query();
        if (array_key_exists('customer_id', $f) && $f['customer_id'] !== null && $f['customer_id'] !== '') {
            if (is_string($f['customer_id'])) {
                // An invalid supplied filter must not become an unfiltered
                // list after hash decoding returns null.
                $f['customer_id'] = Customer::tryDecodeHash($f['customer_id']) ?? -1;
            }
        }

        return CustomerComplaintResource::collection($this->service->list($f));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'severities' => array_map(
                static fn (NcrSeverity $severity): array => ['value' => $severity->value, 'label' => ucfirst($severity->value)],
                NcrSeverity::cases(),
            ),
            'statuses' => array_map(
                static fn (ComplaintStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)],
                ComplaintStatus::cases(),
            ),
        ]]);
    }

    public function show(CustomerComplaint $complaint): CustomerComplaintResource
    {
        return new CustomerComplaintResource($this->service->show($complaint));
    }

    public function store(StoreComplaintRequest $request): CustomerComplaintResource
    {
        return new CustomerComplaintResource($this->service->create($request->validated(), $request->user()));
    }

    /** Retry a failed complaint → Quality NCR handoff. */
    public function retryNcr(Request $request, CustomerComplaint $complaint): CustomerComplaintResource
    {
        return new CustomerComplaintResource(
            $this->service->retryNcrHandoff($complaint, $request->user())
        );
    }

    public function update8D(Request $request, CustomerComplaint $complaint): CustomerComplaintResource
    {
        $validated = $request->validate([
            'd1_team'              => ['nullable', 'string', 'max:5000'],
            'd2_problem'           => ['nullable', 'string', 'max:5000'],
            'd3_containment'       => ['nullable', 'string', 'max:5000'],
            'd4_root_cause'        => ['nullable', 'string', 'max:5000'],
            'd5_corrective_action' => ['nullable', 'string', 'max:5000'],
            'd6_verification'      => ['nullable', 'string', 'max:5000'],
            'd7_prevention'        => ['nullable', 'string', 'max:5000'],
            'd8_recognition'       => ['nullable', 'string', 'max:5000'],
        ]);
        $this->service->update8DReport($complaint, $validated);
        return new CustomerComplaintResource($this->service->show($complaint));
    }

    public function finalize8D(Request $request, CustomerComplaint $complaint): CustomerComplaintResource
    {
        $this->service->finalize8D($complaint, $request->user());
        return new CustomerComplaintResource($this->service->show($complaint));
    }

    public function resolve(CustomerComplaint $complaint): CustomerComplaintResource
    {
        return new CustomerComplaintResource($this->service->resolve($complaint));
    }

    public function close(CustomerComplaint $complaint): CustomerComplaintResource
    {
        return new CustomerComplaintResource($this->service->close($complaint));
    }

    /**
     * Render the 8D report as PDF using the standard pdf._layout.
     */
    public function pdf(CustomerComplaint $complaint): StreamedResponse
    {
        $complaint->load(['customer', 'product', 'eightDReport.finalizer']);
        abort_unless(
            $complaint->eightDReport && $complaint->eightDReport->finalized_at,
            404,
            'The 8D report must be finalised before it can be downloaded.',
        );

        $payload = [
            'complaint' => $complaint,
            'report'    => $complaint->eightDReport,
        ];
        $bytes = $this->renderer->render('pdf.complaint-8d', $payload, [
            'title' => DocumentType::Complaint8D->label(),
        ]);
        $actor = auth()->user();
        $user = $actor instanceof \App\Modules\Auth\Models\User ? $actor : null;
        $document = $this->vault->store($bytes, DocumentType::Complaint8D, $complaint, $user);

        return $this->vault->streamInline($document);
    }
}
