<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierAgreementDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class PortalContractSignatureService
{
    public function issueCustomerDocument(CustomerAgreementDocument $document, User $issuer): CustomerAgreementDocument
    {
        /** @var CustomerAgreementDocument $issued */
        $issued = $this->issue($document, $issuer);

        return $issued;
    }

    public function issueSupplierDocument(SupplierAgreementDocument $document, User $issuer): SupplierAgreementDocument
    {
        /** @var SupplierAgreementDocument $issued */
        $issued = $this->issue($document, $issuer);

        return $issued;
    }

    public function signCustomerDocument(CustomerAgreementDocument $document, CustomerPortalUser $signer, string $typedName): CustomerAgreementDocument
    {
        abort_unless((int) $document->customer_id === (int) $signer->customer_id, 404);

        /** @var CustomerAgreementDocument $signed */
        $signed = $this->sign($document, $signer, $typedName, 'customer_id', 'signed_by_portal_user_id');

        return $signed;
    }

    public function signSupplierDocument(SupplierAgreementDocument $document, SupplierPortalUser $signer, string $typedName): SupplierAgreementDocument
    {
        abort_unless((int) $document->vendor_id === (int) $signer->vendor_id, 404);

        /** @var SupplierAgreementDocument $signed */
        $signed = $this->sign($document, $signer, $typedName, 'vendor_id', 'signed_by_portal_user_id');

        return $signed;
    }

    private function sign(Model $document, CustomerPortalUser|SupplierPortalUser $signer, string $typedName, string $ownerColumn, string $signerColumn): Model
    {
        $typedName = trim($typedName);
        if ($typedName === '' || mb_strlen($typedName) > 200) {
            throw ValidationException::withMessages(['typed_name' => 'Enter your name (up to 200 characters) to sign this agreement.']);
        }
        abort_unless($signer->is_active && $signer->deleted_at === null && ! $signer->must_change_password, 403);
        abort_unless((bool) $signer->is_authorized_signatory, 403);

        return DB::transaction(function () use ($document, $signer, $typedName, $ownerColumn, $signerColumn): Model {
            /** @var Model $locked */
            $locked = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $signerOwnerId = $signer instanceof CustomerPortalUser ? $signer->customer_id : $signer->vendor_id;
            abort_unless((int) $locked->{$ownerColumn} === (int) $signerOwnerId, 404);
            abort_if($locked->signed_at !== null || $locked->signature_status === 'signed', 409, 'This agreement has already been signed.');
            abort_unless($locked->signature_status === 'pending', 404);
            abort_unless($locked->issued_at !== null, 404, 'This agreement has not been issued.');

            $disk = Storage::disk('local');
            abort_unless($disk->exists($locked->file_path), 404);
            $path = $disk->path($locked->file_path);
            $currentHash = hash_file('sha256', $path);
            abort_if($currentHash === false, 409, 'The agreement file could not be verified.');
            if ($locked->document_sha256 !== null && ! hash_equals($locked->document_sha256, $currentHash)) {
                abort(409, 'The agreement file changed after it was issued. Contact the account manager for a new copy.');
            }

            $locked->forceFill([
                'document_sha256' => $locked->document_sha256 ?? $currentHash,
                'signature_status' => 'signed',
                $signerColumn => $signer->id,
                'signed_name' => $typedName,
                'signed_at' => now()->utc(),
                'signed_document_sha256' => $currentHash,
            ])->save();

            return $locked->refresh();
        });
    }

    private function issue(Model $document, User $issuer): Model
    {
        return DB::transaction(function () use ($document, $issuer): Model {
            /** @var Model $locked */
            $locked = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->signature_status === 'draft' && $locked->signed_at === null, 409, 'Only an unsigned draft agreement can be issued.');

            $disk = Storage::disk('local');
            abort_unless($disk->exists($locked->file_path), 404);
            $currentHash = hash_file('sha256', $disk->path($locked->file_path));
            abort_if($currentHash === false, 409, 'The agreement file could not be verified.');
            if ($locked->document_sha256 !== null && ! hash_equals($locked->document_sha256, $currentHash)) {
                abort(409, 'The agreement file changed after it was uploaded. Replace it with a new draft before issuing.');
            }

            $locked->forceFill([
                'signature_status' => 'pending',
                'document_sha256' => $currentHash,
                'issued_by' => $issuer->id,
                'issued_at' => now()->utc(),
            ])->save();

            return $locked->refresh();
        });
    }
}
