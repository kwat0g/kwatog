<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\FxRate;
use App\Modules\Accounting\Requests\StoreFxRateRequest;
use App\Modules\Accounting\Resources\FxRateResource;
use App\Modules\Accounting\Services\CurrencyTranslationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * REC-12 (core) — FX rates + JPY-translated parent statement pack.
 */
class CurrencyController
{
    public function __construct(
        private readonly CurrencyTranslationService $service,
        private readonly SettingsService $settings,
    ) {}

    public function listRates(Request $request): AnonymousResourceCollection
    {
        $q = FxRate::query();
        if ($ccy = $request->query('currency_code')) {
            $q->where('currency_code', strtoupper((string) $ccy));
        }
        return FxRateResource::collection(
            $q->orderByDesc('rate_date')->orderBy('currency_code')->paginate(
                min((int) $request->query('per_page', 50), 200)
            )
        );
    }

    public function storeRate(StoreFxRateRequest $request): FxRateResource
    {
        $data = $request->validated();
        // Upsert on (currency_code, rate_date) so re-entering a day's rate corrects it.
        $rate = FxRate::updateOrCreate(
            ['currency_code' => strtoupper($data['currency_code']), 'rate_date' => $data['rate_date']],
            [
                'rate_to_functional' => $data['rate_to_functional'],
                'source'             => $data['source'] ?? null,
                'created_by'         => $request->user()?->id,
            ],
        );
        return new FxRateResource($rate);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        return $this->guarded(fn () => response()->json([
            'data' => $this->service->translatedTrialBalance($from, $to, $this->currency($request)),
        ]));
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        return $this->guarded(fn () => response()->json([
            'data' => $this->service->translatedIncomeStatement($from, $to, $this->currency($request)),
        ]));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $asOf = $request->filled('as_of') ? Carbon::parse((string) $request->query('as_of')) : now();
        return $this->guarded(fn () => response()->json([
            'data' => $this->service->translatedBalanceSheet($asOf, $this->currency($request)),
        ]));
    }

    private function currency(Request $request): string
    {
        return strtoupper((string) $request->query(
            'currency',
            $this->settings->requiredString('accounting.reporting_currency_code'),
        ));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse((string) $request->query('from')) : now()->startOfMonth();
        $to   = $request->filled('to')   ? Carbon::parse((string) $request->query('to'))   : now()->endOfMonth();
        return [$from, $to];
    }

    /** Translate a missing-rate RuntimeException into a clean 422. */
    private function guarded(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
