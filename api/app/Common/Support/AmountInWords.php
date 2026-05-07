<?php

declare(strict_types=1);

namespace App\Common\Support;

use NumberFormatter;

/**
 * Series E (Task E1) — "Amount in Words" helper used by the Tax Invoice
 * Blade. Falls back to a hand-rolled converter when the `intl` extension
 * is not installed (test environments, minimal containers).
 */
class AmountInWords
{
    /** @return string  e.g. "Four Hundred Eighty-Six Thousand Five Hundred Pesos and 50/100 Only" */
    public static function peso(float|string $amount): string
    {
        $amount = (float) $amount;
        $whole  = (int) floor(abs($amount));
        $cents  = (int) round((abs($amount) - $whole) * 100);

        if (class_exists(NumberFormatter::class)) {
            try {
                $formatter = new NumberFormatter('en', NumberFormatter::SPELLOUT);
                $words = $formatter->format($whole) ?: (string) $whole;
            } catch (\Throwable $e) {
                $words = self::numberToWords($whole);
            }
        } else {
            $words = self::numberToWords($whole);
        }

        $words = ucwords($words);
        return sprintf('%s Pesos and %02d/100 Only', $words, $cents);
    }

    /** Lightweight fallback covering 0..999_999_999_999. */
    private static function numberToWords(int $n): string
    {
        if ($n === 0) return 'zero';

        $units = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
            'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
            'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $chunk = function (int $num) use (&$chunk, $units, $tens): string {
            if ($num < 20) return $units[$num];
            if ($num < 100) {
                return rtrim($tens[(int) ($num / 10)] . '-' . $units[$num % 10], '-');
            }
            if ($num < 1000) {
                $rest = $num % 100;
                return $units[(int) ($num / 100)] . ' hundred' . ($rest ? ' ' . $chunk($rest) : '');
            }
            return (string) $num;
        };

        $billion  = (int) floor($n / 1_000_000_000);
        $million  = (int) floor(($n % 1_000_000_000) / 1_000_000);
        $thousand = (int) floor(($n % 1_000_000) / 1_000);
        $hundred  = $n % 1_000;

        $parts = [];
        if ($billion)  $parts[] = $chunk($billion) . ' billion';
        if ($million)  $parts[] = $chunk($million) . ' million';
        if ($thousand) $parts[] = $chunk($thousand) . ' thousand';
        if ($hundred)  $parts[] = $chunk($hundred);

        return implode(' ', $parts);
    }
}
