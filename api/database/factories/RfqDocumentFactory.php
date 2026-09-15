<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RfqDocument> */
class RfqDocumentFactory extends Factory
{
    protected $model = RfqDocument::class;
    public function definition(): array
    {
        return ['request_for_quote_id' => RequestForQuote::factory(), 'document_type' => 'quotation_pdf', 'original_filename' => 'quotation.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024, 'file_path' => 'rfqs/test/quotation.pdf'];
    }
}
