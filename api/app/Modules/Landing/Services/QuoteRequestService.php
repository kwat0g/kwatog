<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Landing\Models\QuoteRequest;
use App\Modules\Landing\Notifications\QuoteRequestReceivedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class QuoteRequestService
{
    private const SALES_INBOX = 'sales@ogami.com.ph';

    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function create(array $data, ?UploadedFile $drawing, Request $request): QuoteRequest
    {
        // Persist the upload BEFORE opening the transaction so a DB rollback
        // never leaves the row gone but the file orphaned on disk. If the
        // insert fails, we delete the just-stored file in the catch below.
        if ($drawing !== null) {
            $data['drawing_path']          = $drawing->store('quote-drawings', 'local');
            $data['drawing_original_name'] = $this->sanitizeFilename($drawing->getClientOriginalName());
        }

        $data['ip_address'] = $request->ip();
        $data['user_agent'] = substr((string) $request->userAgent(), 0, 255);

        try {
            $quote = DB::transaction(function () use ($data) {
                $data['request_no'] = $this->sequences->generate('quote_request');

                return QuoteRequest::create($data);
            });
        } catch (\Throwable $e) {
            if (! empty($data['drawing_path'])) {
                Storage::disk('local')->delete($data['drawing_path']);
            }
            throw $e;
        }

        try {
            Notification::route('mail', self::SALES_INBOX)
                ->notify(new QuoteRequestReceivedNotification($quote));
        } catch (\Throwable $e) {
            Log::warning('QuoteRequestReceivedNotification failed to send', [
                'request_no' => $quote->request_no,
                'error'      => $e->getMessage(),
            ]);
        }

        return $quote;
    }

    /**
     * Strip directory components and anything outside [A-Za-z0-9._-] from a
     * client-supplied filename before we persist it — defence-in-depth against
     * stored-XSS / odd characters surfacing in a future admin view or the
     * notification email.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'drawing';

        return mb_substr($name, 0, 255);
    }
}
