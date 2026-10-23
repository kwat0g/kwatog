<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Mail\SupplierPoDecisionMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

class SupplierPoDecisionMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_decision_email_uses_values_captured_when_the_decision_was_created(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);
        $vendor = Vendor::factory()->create([
            'name' => 'Kōyō Components',
            'contact_person' => 'Aiko Tanaka',
        ]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'po_number' => 'PO-2026-0042',
            'total_amount' => '125000.00',
        ]);
        $response = PurchaseOrderResponse::create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $vendor->id,
            'response_type' => 'propose',
            'status' => 'accepted',
            'proposed_delivery_date' => '2026-11-02',
        ]);

        $mail = new SupplierPoDecisionMail($purchaseOrder, $response, 'accept');
        $purchaseOrder->forceFill(['po_number' => 'PO-CHANGED', 'total_amount' => '999999.00'])->save();
        $vendor->forceFill(['contact_person' => 'Changed Contact'])->save();
        $response->forceFill(['proposed_delivery_date' => '2026-12-31'])->save();

        $content = $mail->content();
        $html = $mail->render();
        $text = (string) app(Markdown::class)->renderText($content->markdown, $content->with);

        $this->assertStringContainsString('PO-2026-0042', $mail->envelope()->subject);
        $this->assertStringContainsString('Aiko Tanaka', $html);
        $this->assertStringContainsString('PO-2026-0042', $html);
        $this->assertStringContainsString('125,000.00', $html);
        $this->assertStringContainsString('Proposed changes', $html);
        $this->assertStringContainsString('Nov 02, 2026', $html);
        $this->assertStringContainsString('https://erp.example.test/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id, $html);
        $this->assertStringContainsString('PO-2026-0042', $text);
        $this->assertStringNotContainsString('PO-CHANGED', $html);
        $this->assertStringNotContainsString('999,999.00', $html);
        $this->assertStringNotContainsString('Changed Contact', $html);
        $this->assertStringNotContainsString('Dec 31, 2026', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('undefined', $html);
        $this->assertStringNotContainsString('null', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringContainsString('https://erp.example.test/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id, $text);
        $this->assertStringContainsString('Philippine Ogami Corporation', $html);
    }
}
