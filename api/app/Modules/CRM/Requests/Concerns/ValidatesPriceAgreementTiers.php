<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests\Concerns;

use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Models\PriceAgreement;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesPriceAgreementTiers
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $agreement = $this->route('priceAgreement');
            $agreement = $agreement instanceof PriceAgreement ? $agreement : null;

            $method = $this->input('pricing_method') ?: $agreement?->pricing_method;
            $method = $method instanceof PricingMethod ? $method->value : ((string) $method ?: PricingMethod::default()->value);

            $payload = $this->all();
            $tiers = array_key_exists('tiers', $payload) ? $this->input('tiers') : $agreement?->tiers;

            if ($method === PricingMethod::Tiered->value && (! is_array($tiers) || $tiers === [])) {
                $validator->errors()->add('tiers', 'Tiered pricing requires at least one price tier.');
                return;
            }

            if ($method === PricingMethod::Flat->value && is_array($tiers) && $tiers !== []) {
                $validator->errors()->add('tiers', 'Price tiers are only allowed for tiered pricing.');
                return;
            }

            if (! is_array($tiers)) {
                return;
            }

            $previous = 0;
            foreach ($tiers as $index => $tier) {
                if (! is_array($tier) || ! isset($tier['min_qty']) || ! ctype_digit((string) $tier['min_qty'])) {
                    continue;
                }

                $minQty = (int) $tier['min_qty'];
                if ($minQty <= $previous) {
                    $validator->errors()->add(
                        "tiers.{$index}.min_qty",
                        'Tier minimum quantities must be strictly ascending and unique.',
                    );
                }
                $previous = $minQty;
            }
        });
    }
}
