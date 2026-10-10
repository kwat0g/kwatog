<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Requests\DecideItemCostReviewRequest;
use App\Modules\Inventory\Resources\ItemCostReviewResource;
use App\Modules\Inventory\Services\ItemCostReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemCostReviewController
{
    public function __construct(private readonly ItemCostReviewService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return ItemCostReviewResource::collection($this->service->list());
    }

    public function decide(DecideItemCostReviewRequest $request, ItemCostReview $itemCostReview): ItemCostReviewResource
    {
        return new ItemCostReviewResource($this->service->decide($itemCostReview, $request->user(), $request->validated('decision'), $request->validated('notes'), (int) $request->validated('version')));
    }

    public function renew(Request $request, ItemCostReview $itemCostReview): ItemCostReviewResource
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);

        return new ItemCostReviewResource($this->service->renew($itemCostReview, $request->user(), $data['notes']));
    }
}
