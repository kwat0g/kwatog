<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Common\Concerns\ResolvesHashIds;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Resources\CustomerComplaintResource;
use App\Modules\CRM\Services\ComplaintService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ComplaintController
{
    public function __construct(private readonly ComplaintService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->query();
        if (! empty($f['customer_id']) && is_string($f['customer_id'])) {
            $f['customer_id'] = Customer::tryDecodeHash($f['customer_id']);
        }
        return CustomerComplaintResource::collection($this->service->list($f));
    }

    public function show(CustomerComplaint $complaint): CustomerComplaintResource
    {
        return new CustomerComplaintResource($this->service->show($complaint));
    }

    public function store(Request $request): CustomerComplaintResource
    {
        $data = $request->validate([
            'customer_id'       => ['required', 'string'],
            'product_id'        => ['nullable', 'string'],
            'sales_order_id'    => ['nullable', 'string'],
            'received_date'     => ['required', 'date'],
            'severity'          => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'description'       => ['required', 'string', 'max:5000'],
            'affected_quantity' => ['nullable', 'integer', 'min:0'],
            'assigned_to'       => ['nullable', 'string'],
        ]);

        // Decode hash IDs
        $payload = [
            'customer_id'       => Customer::decodeHash($data['customer_id']),
            'product_id'        => ! empty($data['product_id']) ? Product::tryDecodeHash($data['product_id']) : null,
            'sales_order_id'    => ! empty($data['sales_order_id']) ? SalesOrder::tryDecodeHash($data['sales_order_id']) : null,
            'received_date'     => $data['received_date'],
            'severity'          => $data['severity'],
            'description'       => $data['description'],
            'affected_quantity' => (int) ($data['affected_quantity'] ?? 0),
            'assigned_to'       => ! empty($data['assigned_to']) ? User::tryDecodeHash($data['assigned_to']) : null,
        ];

        return new CustomerComplaintResource($this->service->create($payload, $request->user()));
    }

    public function update8D(Request $request, CustomerComplaint $complaint): CustomerComplaintResource
    {
        $request->validate([
            'd1_team'              => ['nullable', 'string', 'max:5000'],
            'd2_problem'           => ['nullable', 'string', 'max:5000'],
            'd3_containment'       => ['nullable', 'string', 'max:5000'],
            'd4_root_cause'        => ['nullable', 'string', 'max:5000'],
            'd5_corrective_action' => ['nullable', 'string', 'max:5000'],
            'd6_verification'      => ['nullable', 'string', 'max:5000'],
            'd7_prevention'        => ['nullable', 'string', 'max:5000'],
            'd8_recognition'       => ['nullable', 'string', 'max:5000'],
        ]);
        $this->service->update8DReport($complaint, $request->all());
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
    public function pdf(CustomerComplaint $complaint, SettingsService $settings)
    {
        $complaint->load(['customer', 'product', 'eightDReport.finalizer']);
        if (! $complaint->eightDReport) abort(404);

        $payload = [
            'company'   => [
                'name'    => (string) $settings->get('company.name', 'Philippine Ogami Corporation'),
                'address' => (string) $settings->get('company.address', 'FCIE, Dasmariñas, Cavite, Philippines'),
                'tin'     => $settings->get('company.tin'),
            ],
            'user'      => optional(request()->user())->name,
            'complaint' => $complaint,
            'report'    => $complaint->eightDReport,
        ];
        return Pdf::loadView('pdf.complaint-8d', $payload)
            ->setPaper('a4')
            ->stream("8D-{$complaint->complaint_number}.pdf");
    }
}
