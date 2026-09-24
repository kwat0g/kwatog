<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\B2B\Enums\SupplierShippingDocumentType;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\B2B\Models\PortalShippingDocument>
 */
class PortalShippingDocumentFactory extends Factory
{
    protected $model = PortalShippingDocument::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'document_type' => $this->faker->randomElement(SupplierShippingDocumentType::cases())->value,
            'file_path' => 'portal/shipping-docs/test-' . $this->faker->unique()->numerify('####'),
            'original_filename' => $this->faker->word() . '.pdf',
            'file_size_bytes' => $this->faker->numberBetween(1000, 1000000),
            'content_sha256' => $this->faker->sha256(),
            'mime_type' => 'application/pdf',
            'notes' => $this->faker->optional()->sentence(),
            'uploaded_by' => SupplierPortalUser::factory(),
            'uploaded_at' => $this->faker->dateTime(),
        ];
    }
}
