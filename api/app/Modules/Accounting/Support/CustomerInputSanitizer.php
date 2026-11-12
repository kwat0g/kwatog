<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

final class CustomerInputSanitizer
{
    public const CUSTOMER_NAME_PATTERN = '/\A[\pL\pN][\pL\pN\pM .,&()\/+\-#\x{27}]*\z/u';
    public const CONTACT_NAME_PATTERN = '/\A[\pL\pM][\pL\pM .\-\x{27}]*\z/u';
    public const ROLE_TITLE_PATTERN = '/\A[\pL\pN][\pL\pN\pM .,&()\/+\-\x{27}]*\z/u';
    public const ADDRESS_PATTERN = '/\A[\pL\pN#][\pL\pN\pM\s.,#&()\/:+\-\x{27}]*\z/u';
    public const TAX_ID_PATTERN = '/\A[\pL\pN][\pL\pN .\/-]*\z/u';
    public const PHONE_PATTERN = '/\A\+?[0-9][0-9(). -]*\z/';
    public const WARRANTY_LABEL_PATTERN = '/\A[\pL\pN][\pL\pN\pM .,&();:\/+\-\x{27}]*\z/u';
    public const OTHER_ISSUE_PATTERN = '/\A[\pL\pN][\pL\pN\pM\s.,;:!?&()\/+\-#\x{27}]*\z/u';
    public const NORMALIZED_NAME_SQL = "LOWER(REGEXP_REPLACE(BTRIM(name), '[[:space:]]+', ' ', 'g'))";

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public static function sanitizePayload(array $payload): array
    {
        $singleLineFields = [
            'name', 'contact_person', 'contact_person_role', 'email', 'phone',
            'delivery_contact_person', 'delivery_contact_phone', 'tin',
        ];
        $multiLineFields = ['address', 'delivery_address'];

        foreach ($singleLineFields as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = self::normalizeText($payload[$field]);
                if ($field === 'email') {
                    $payload[$field] = mb_strtolower($payload[$field], 'UTF-8');
                }
            }
        }

        foreach ($multiLineFields as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = self::normalizeText($payload[$field], multiline: true);
            }
        }

        if (isset($payload['product_warranties']) && is_array($payload['product_warranties'])) {
            foreach ($payload['product_warranties'] as $index => $warranty) {
                if (! is_array($warranty)) {
                    continue;
                }
                foreach (['label', 'additional_terms'] as $field) {
                    if (isset($warranty[$field]) && is_string($warranty[$field])) {
                        $warranty[$field] = self::normalizeText($warranty[$field], multiline: $field === 'additional_terms');
                    }
                }
                $payload['product_warranties'][$index] = $warranty;
            }
        }

        return $payload;
    }

    public static function normalizeText(string $value, bool $multiline = false): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if (! $multiline) {
            return trim((string) preg_replace('/\s+/u', ' ', $value));
        }

        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[\t ]+/u', ' ', $line)),
            explode("\n", $value),
        );

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", implode("\n", $lines)));
    }

    public static function isPlaceholder(string $value): bool
    {
        $key = mb_strtolower(trim($value), 'UTF-8');
        $key = (string) preg_replace('/[^\pL\pN]/u', '', $key);
        $placeholders = [
            'abc', 'address', 'asdf', 'company', 'companyname', 'contact', 'contactperson', 'customer', 'customer1', 'customername',
            'democompany', 'democustomer', 'dummy', 'entername', 'name', 'newcustomer',
            'email', 'entercontact', 'enterphone', 'noaddress', 'notprovided', 'placeholder', 'phone',
            'qwerty', 'sample', 'samplecompany', 'samplecustomer', 'test', 'testcompany',
            'testcustomer', 'unknown', 'youraddress', 'yourcontact', 'youremail', 'yourname', 'yourphone',
        ];

        return in_array($key, $placeholders, true) || preg_match('/\A(.)\1{3,}\z/u', $key) === 1;
    }

    public static function isPlausiblePhone(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (preg_match(self::PHONE_PATTERN, $value) !== 1 || strlen($digits) < 7 || strlen($digits) > 15) {
            return false;
        }

        return preg_match('/\A(.)\1+\z/', $digits) !== 1;
    }

    public static function isValidCalendarDate(string $value): bool
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    public static function hasInvalidOtherCoveredIssue(string $terms): bool
    {
        if (preg_match('/(?:\A|\n)Other covered issue:\s*([^\n]*)/iu', $terms, $matches) !== 1) {
            return false;
        }

        $issue = self::normalizeText($matches[1]);

        return mb_strlen($issue) < 2
            || mb_strlen($issue) > 500
            || self::isPlaceholder($issue)
            || preg_match(self::OTHER_ISSUE_PATTERN, $issue) !== 1;
    }
}
