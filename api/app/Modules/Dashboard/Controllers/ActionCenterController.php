<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\ActionCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionCenterController
{
    public function __construct(private readonly ActionCenterService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->for($request->user())]);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $data = $this->service->for($request->user());
        $data['items'] = array_values(array_filter(
            $data['items'],
            fn (array $item): bool => ! in_array($item['category'], ['approval'], true),
        ));
        $data['summary']['total'] = count($data['items']);

        return response()->json(['data' => $data]);
    }
}
