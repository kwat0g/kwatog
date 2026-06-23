<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Skill;
use App\Modules\HR\Requests\StoreSkillRequest;
use App\Modules\HR\Requests\UpdateSkillRequest;
use App\Modules\HR\Resources\SkillResource;
use App\Modules\HR\Services\SkillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SkillController
{
    public function __construct(private readonly SkillService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SkillResource::collection($this->service->list($request->query()));
    }

    public function store(StoreSkillRequest $request): SkillResource
    {
        return new SkillResource($this->service->create($request->validated()));
    }

    public function show(Skill $skill): SkillResource
    {
        return new SkillResource($skill);
    }

    public function update(UpdateSkillRequest $request, Skill $skill): SkillResource
    {
        return new SkillResource($this->service->update($skill, $request->validated()));
    }

    public function deactivate(Skill $skill): SkillResource
    {
        return new SkillResource($this->service->deactivate($skill));
    }
}
