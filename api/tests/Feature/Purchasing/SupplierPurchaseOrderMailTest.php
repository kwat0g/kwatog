<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Mail\SupplierPurchaseOrderMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

class SupplierPurchaseOrderMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_uses_publication_snapshot_and_summarizes_long_orders(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);
        $vendor = Vendor::factory()->create([
            'name' => 'Koyo Plastics',
            'contact_person' => 'Aiko Tanaka',
            'payment_terms_days' => 30,
        ]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'po_number' => 'PO-2026-0088',
            'date' => '2026-10-20',
            'expected_delivery_date' => '2026-11-12',
            'total_amount' => '1234.56',
        ]);
        $firstItem = null;
        foreach (range(1, 11) as $number) {
            $item = Item::factory()->create(['name' => $number === 1 ? 'Bracket [A]' : sprintf('Part %02d', $number)]);
            $line = PurchaseOrderItem::create([
                'purchase_order_id' => $purchaseOrder->id,
                'item_id' => $item->id,
                'description' => sprintf('Part %02d description', $number),
                'quantity' => '2.500',
                'unit' => 'pcs',
                'unit_price' => '25.00',
                'total' => '62.50',
            ]);
            $firstItem ??= $line;
        }

        $mail = new SupplierPurchaseOrderMail($purchaseOrder);
        $purchaseOrder->forceFill([
            'po_number' => 'PO-CHANGED',
            'total_amount' => '999999.00',
        ])->save();
        $vendor->forceFill(['contact_person' => 'Changed Contact', 'payment_terms_days' => 90])->save();
        $firstItem->forceFill(['quantity' => '9.000', 'total' => '999.00'])->save();

        $content = $mail->content();
        $html = $mail->render();
        $text = (string) app(Markdown::class)->renderText($content->markdown, $content->with);

        $this->assertStringContainsString('[Ogami Philippines] Purchase order ready: PO-2026-0088', $mail->envelope()->subject);
        $this->assertStringContainsString('Aiko Tanaka', $html);
        $this->assertStringContainsString('Oct 20, 2026', $html);
        $this->assertStringContainsString('Nov 12, 2026', $html);
        $this->assertStringContainsString('PHP 1,234.56', $html);
        $this->assertStringContainsString('30 days', $html);
        $this->assertStringContainsString('2.50 pcs', $html);
        $this->assertStringContainsString('PHP 62.50', $html);
        $this->assertStringContainsString('Bracket [A]', $html);
        $this->assertStringContainsString('1 more item is available', $html);
        $this->assertStringContainsString('Part 10', $html);
        $this->assertStringNotContainsString('Part 11', $html);
        $this->assertStringContainsString('https://erp.example.test/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id, $html);
        $this->assertStringContainsString('https://erp.example.test/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id, $text);
        $this->assertStringContainsString('Philippine Ogami Corporation', $html);
        $this->assertStringNotContainsString('PO-CHANGED', $html);
        $this->assertStringNotContainsString('999,999.00', $html);
        $this->assertStringNotContainsString('Changed Contact', $html);
        $this->assertStringNotContainsString('90 days', $html);
        $this->assertStringNotContainsString('9.00 pcs', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('undefined', $html);
        $this->assertStringNotContainsString('null', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertLessThan(102 * 1024, strlen($html));
    }
}
