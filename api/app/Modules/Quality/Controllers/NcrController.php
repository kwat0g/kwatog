<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Requests\CreateNcrRequest;
use App\Modules\Quality\Resources\NcrActionResource;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class NcrController
{
    public function __construct(private readonly NcrService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return NcrResource::collection($this->service->list($request->query()));
    }

    public function show(NonConformanceReport $ncr): NcrResource
    {
        return new NcrResource($this->service->show($ncr));
    }

    public function store(CreateNcrRequest $request): NcrResource
    {
        return new NcrResource($this->service->create($request->validated(), $request->user()));
    }

    public function addAction(Request $request, NonConformanceReport $ncr): NcrActionResource
    {
        $request->validate([
            'action_type'  => ['required', Rule::in(NcrActionType::values())],
            'description'  => ['required', 'string', 'max:5000'],
            'performed_at' => ['nullable', 'date'],
        ]);
        $action = $this->service->addAction($ncr, $request->only(['action_type', 'description', 'performed_at']), $request->user());
        return new NcrActionResource($action);
    }

    public function setDisposition(Request $request, NonConformanceReport $ncr): NcrResource
    {
        $data = $request->validate([
            'disposition'       => ['required', Rule::in(NcrDisposition::values())],
            'root_cause'        => ['nullable', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
        ]);
        $ncr = $this->service->setDisposition(
            $ncr,
            (string) $data['disposition'],
            $data['root_cause']        ?? null,
            $data['corrective_action'] ?? null,
        );
        return new NcrResource($ncr);
    }

    public function close(Request $request, NonConformanceReport $ncr): NcrResource
    {
        return new NcrResource($this->service->close($ncr, $request->user()));
    }

    public function cancel(Request $request, NonConformanceReport $ncr): NcrResource
    {
        $reason = $request->input('reason');
        return new NcrResource($this->service->cancel($ncr, is_string($reason) ? $reason : null, $request->user()));
    }
}
