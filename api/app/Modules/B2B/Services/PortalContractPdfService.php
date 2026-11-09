<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Modules\B2B\Models\PortalContractTemplate;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PortalContractPdfService
{
    /** @var array<string, list<string>> */
    private const ALLOWED_KEYS = [
        'customer' => [
            'customer_name', 'customer_contact_name', 'customer_contact_role', 'customer_email', 'customer_phone',
            'customer_address', 'delivery_address', 'payment_terms', 'effective_date', 'expiry_date',
            'allowed_products_summary', 'warranty_summary',
        ],
        'supplier' => [
            'vendor_name', 'vendor_contact_name', 'vendor_email', 'vendor_phone', 'vendor_address',
            'payment_terms', 'effective_date', 'expiry_date',
        ],
    ];

    public function __construct(private readonly PortalContractPdfProcessor $processor) {}

    /** @return list<string> */
    public static function allowedKeysForRealm(string $realm): array
    {
        return self::ALLOWED_KEYS[$realm] ?? [];
    }

    /** @return list<array{name: string, type: string|null}> */
    public function discoverFields(string $pdfPath): array
    {
        return $this->processor->discoverFields($pdfPath);
    }

    /** @param array<string, scalar|null> $recordValues */
    public function fill(PortalContractTemplate $template, array $recordValues): string
    {
        if (! array_key_exists($template->realm, self::ALLOWED_KEYS)) {
            throw ValidationException::withMessages(['realm' => 'This contract template has an unsupported portal type.']);
        }

        $knownPdfFields = collect($template->pdf_fields ?? [])
            ->map(static fn (array|string $field): string => is_array($field) ? (string) ($field['name'] ?? '') : $field)
            ->filter()
            ->all();
        $mappings = $template->field_mappings ?? [];
        $allowedKeys = self::ALLOWED_KEYS[$template->realm];

        foreach ($mappings as $pdfField => $recordKey) {
            if (! in_array($pdfField, $knownPdfFields, true)) {
                throw ValidationException::withMessages(['field_mappings' => "PDF field '{$pdfField}' was not found in this template."]);
            }
            if (! in_array($recordKey, $allowedKeys, true)) {
                throw ValidationException::withMessages(['field_mappings' => "Record field '{$recordKey}' is not approved for {$template->realm} contracts."]);
            }
        }

        $requiredFields = $template->required_fields ?? [];
        $fillValues = [];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            $recordKey = $mappings[$field] ?? null;
            if ($recordKey === null || ! array_key_exists($recordKey, $recordValues) || ! is_scalar($recordValues[$recordKey]) || trim((string) $recordValues[$recordKey]) === '') {
                $missingFields[] = $field;
            }
        }
        if ($missingFields !== []) {
            throw ValidationException::withMessages(['field_mappings' => 'Map every required PDF field to a customer or supplier value before issuing this contract.']);
        }

        foreach ($mappings as $pdfField => $recordKey) {
            $value = $recordValues[$recordKey] ?? null;
            if (! is_scalar($value)) {
                continue;
            }
            $fillValues[$pdfField] = (string) $value;
        }

        $sourcePath = Storage::disk('local')->path($template->file_path);
        if (! is_file($sourcePath) || ! hash_equals($template->source_sha256, (string) hash_file('sha256', $sourcePath))) {
            abort(409, 'The contract template changed after it was uploaded. Upload a new template version.');
        }

        if ($fillValues === []) {
            $directory = storage_path('app/private/portal-contract-tmp');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Private contract processing storage is unavailable.');
            }
            $copyPath = $directory.'/'.Str::uuid().'.pdf';
            if (! copy($sourcePath, $copyPath)) {
                throw new \RuntimeException('The contract template could not be copied for issue.');
            }
            chmod($copyPath, 0600);

            return $copyPath;
        }

        return $this->processor->fill($sourcePath, $fillValues);
    }
}
