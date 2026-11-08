<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Exceptions\ContactInquiryStateConflictException;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Requests\ListContactInquiryRequest;
use App\Modules\Landing\Resources\ContactInquiryResource;
use App\Modules\Landing\Services\ContactInquiryInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ContactInquiryInboxController
{
    public function __construct(private readonly ContactInquiryInboxService $service) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(
                static fn (ContactInquiryStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                ContactInquiryStatus::cases(),
            ),
        ]]);
    }

    public function index(ListContactInquiryRequest $request): AnonymousResourceCollection
    {
        return ContactInquiryResource::collection($this->service->list($request->validated()));
    }

    public function show(ContactInquiry $inquiry): ContactInquiryResource
    {
        return new ContactInquiryResource($this->service->show($inquiry));
    }

    public function updateStatus(Request $request, ContactInquiry $inquiry): ContactInquiryResource|JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['new', 'in_progress', 'closed'])],
            'expected_status' => ['sometimes', Rule::enum(ContactInquiryStatus::class)],
        ]);

        try {
            $inquiry = $this->service->updateStatus(
                $inquiry,
                ContactInquiryStatus::from($validated['status']),
                isset($validated['expected_status'])
                    ? ContactInquiryStatus::from($validated['expected_status'])
                    : null,
            );
        } catch (ContactInquiryStateConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], 409);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new ContactInquiryResource($inquiry);
    }
}
