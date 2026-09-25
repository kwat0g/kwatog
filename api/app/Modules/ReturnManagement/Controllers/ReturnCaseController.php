<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Controllers;

use App\Common\Services\SystemUserResolver;
use Illuminate\Routing\Controller;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnCaseAttachment;
use App\Modules\ReturnManagement\Requests\ActOnReturnCaseRequest;
use App\Modules\ReturnManagement\Requests\StoreReturnCaseRequest;
use App\Modules\ReturnManagement\Resources\ReturnCaseResource;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\ReturnManagement\Services\ReturnCaseSettlementService;
use App\Modules\ReturnManagement\Services\ReturnCaseSourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ReturnCaseController extends Controller
{
    public function __construct(
        private readonly ReturnCaseService $cases,
        private readonly ReturnCaseSourceService $sources,
        private readonly ReturnCaseSettlementService $settlements,
        private readonly SystemUserResolver $system,
    ) {}

    public function index(Request $request)
    {
        [$customer, $supplier] = $this->parties($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(\App\Modules\ReturnManagement\Enums\ReturnCaseStatus::values())],
            'type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return ReturnCaseResource::collection($this->cases->list($filters, $customer?->customer_id, $supplier?->vendor_id));
    }

    public function sources(Request $request)
    {
        [$customer] = $this->parties($request);
        $data = $request->validate(['type' => ['nullable', Rule::in(['customer', 'supplier'])], 'search' => ['nullable', 'string', 'max:100']]);
        return response()->json(['data' => $this->sources->sources($customer?->customer_id, $data['type'] ?? 'customer', $data['search'] ?? '')]);
    }

    public function sourceOptions(Request $request)
    {
        [$customer] = $this->parties($request);
        $data = $request->validate(['source_kind' => ['required', 'string'], 'source_id' => ['required', 'string', 'max:100']]);
        return response()->json(['data' => $this->sources->options($this->sources->resolve($data['source_kind'], $data['source_id'], $customer?->customer_id))]);
    }

    public function store(StoreReturnCaseRequest $request)
    {
        [$customer] = $this->parties($request);
        $create = fn () => $this->cases->create($request->validated(), $customer ? $this->system->user() : $request->user(), $customer);
        $case = $customer ? $this->system->impersonate($create) : $create();
        return (new ReturnCaseResource($case))->response()->setStatusCode(201);
    }

    public function reportNotArrived(\App\Modules\ReturnManagement\Requests\ReportDeliveryNotArrivedRequest $request, \App\Modules\SupplyChain\Models\Delivery $delivery)
    {
        $case = $this->system->impersonate(fn () => $this->cases->reportNotArrived(
            $delivery, $request->validated(), $this->system->user(), $request->user('customer_portal'),
        ));
        return (new ReturnCaseResource($case))->response()->setStatusCode(201);
    }

    public function show(Request $request, ReturnCase $returnCase)
    {
        $this->assertAccess($request, $returnCase);
        return new ReturnCaseResource($this->cases->show($returnCase));
    }

    public function act(ActOnReturnCaseRequest $request, ReturnCase $returnCase)
    {
        $this->assertAccess($request, $returnCase);
        $actor = $this->actor($request);
        $action = fn () => $this->cases->act($returnCase, $request->validated(), $actor['type'] === 'internal' ? $request->user() : $this->system->user(), $actor);
        $case = $actor['type'] === 'internal' ? $action() : $this->system->impersonate($action);
        return new ReturnCaseResource($case);
    }

    public function resolutionOptions(Request $request, ReturnCase $returnCase)
    {
        return response()->json(['data' => $this->settlements->options($returnCase)]);
    }

    public function attach(Request $request, ReturnCase $returnCase)
    {
        $this->assertAccess($request, $returnCase);
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240']]);
        $actor = $this->actor($request);
        $action = fn () => $this->cases->attach($returnCase, $request->file('file'), $actor);
        $attachment = $actor['type'] === 'internal' ? $action() : $this->system->impersonate($action);
        return response()->json(['data' => ['id' => $attachment->hash_id, 'file_name' => $attachment->file_name]], 201);
    }

    public function download(Request $request, ReturnCase $returnCase, ReturnCaseAttachment $attachment)
    {
        $this->assertAccess($request, $returnCase);
        abort_unless((int) $attachment->return_case_id === (int) $returnCase->id, 404);
        [$customer, $supplier] = $this->parties($request);
        if ($customer || $supplier) {
            $attachment->load('event');
            abort_unless($attachment->event && $attachment->event->is_public, 404);
        }
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        return Storage::disk('local')->download($attachment->path, $attachment->file_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function parties(Request $request): array
    {
        return [
            $request->is('api/v1/b2b/customer/*') ? $request->user('customer_portal') : null,
            $request->is('api/v1/b2b/supplier/*') ? $request->user('supplier_portal') : null,
        ];
    }

    private function assertAccess(Request $request, ReturnCase $case): void
    {
        [$customer, $supplier] = $this->parties($request);
        abort_if($customer && ($case->type->value !== 'customer' || (int) $case->customer_id !== (int) $customer->customer_id), 403);
        abort_if($supplier && ($case->type->value !== 'supplier' || (int) $case->vendor_id !== (int) $supplier->vendor_id), 403);
    }

    private function actor(Request $request): array
    {
        [$customer, $supplier] = $this->parties($request);
        $user = $customer ?? $supplier ?? $request->user();
        return ['type' => $customer ? 'customer' : ($supplier ? 'supplier' : 'internal'), 'id' => $user->id, 'name' => $user->name];
    }
}
