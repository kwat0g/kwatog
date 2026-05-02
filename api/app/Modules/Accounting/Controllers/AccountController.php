<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Requests\StoreAccountRequest;
use App\Modules\Accounting\Requests\UpdateAccountRequest;
use App\Modules\Accounting\Resources\AccountResource;
use App\Modules\Accounting\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountController
{
    public function __construct(private readonly AccountService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AccountResource::collection($this->service->list($request->query()));
    }

    public function tree(): JsonResponse
    {
        $tree = $this->service->tree();
        $resource = AccountResource::collection(collect($tree));
        return response()->json(['data' => $resource->resolve()]);
    }

    public function show(Account $account): AccountResource
    {
        $account->load(['parent:id,code,name', 'children:id,code,name,parent_id']);
        return new AccountResource($account);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        try {
            $account = $this->service->create($request->validated());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource|JsonResponse
    {
        try {
            $account = $this->service->update($account, $request->validated());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new AccountResource($account);
    }

    public function deactivate(Account $account): AccountResource|JsonResponse
    {
        try {
            $account = $this->service->deactivate($account);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new AccountResource($account);
    }
}
