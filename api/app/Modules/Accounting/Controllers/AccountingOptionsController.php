<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Enums\CreditNoteType;
use App\Modules\Accounting\Enums\CreditNoteStatus;
use Illuminate\Http\JsonResponse;

class AccountingOptionsController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'payment_methods' => array_map(
                static fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                PaymentMethod::cases(),
            ),
            'account_types' => array_map(
                static fn (AccountType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'default_normal_balance' => $type->defaultNormalBalance()->value,
                ],
                AccountType::cases(),
            ),
            'normal_balances' => array_map(
                static fn (NormalBalance $balance): array => ['value' => $balance->value, 'label' => $balance->label()],
                NormalBalance::cases(),
            ),
            'credit_note_types' => array_map(
                static fn (CreditNoteType $type): array => ['value' => $type->value, 'label' => $type->label()],
                CreditNoteType::cases(),
            ),
            'credit_note_statuses' => array_map(
                static fn (CreditNoteStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                CreditNoteStatus::cases(),
            ),
        ]]);
    }
}
