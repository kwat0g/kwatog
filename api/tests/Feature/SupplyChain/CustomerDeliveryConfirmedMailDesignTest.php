<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\SupplyChain\Mail\CustomerDeliveryConfirmedMail;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

final class CustomerDeliveryConfirmedMailDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_notice_uses_company_shell_manila_time_and_customer_delivery_route(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);
        $mail = new CustomerDeliveryConfirmedMail(new Delivery, null, [], $this->snapshot([
            'delivered_at' => '2026-04-03T00:15:00+00:00',
        ]));
        $html = $mail->render();
        $text = (string) app(Markdown::class)->renderText($mail->content()->markdown, $mail->content()->with);
        $url = 'https://erp.example.test/portal/customer/deliveries/deliveryhash123';
        $previewDirectory = getenv('EMAIL_PREVIEW_DIR');
        if (is_string($previewDirectory) && trim($previewDirectory) !== '') {
            if (! is_dir($previewDirectory)) {
                mkdir($previewDirectory, 0777, true);
            }
            file_put_contents($previewDirectory.'/delivery-confirmed.html', $html);
        }

        $this->assertSame('[Ogami Philippines] Delivery confirmed: DR-2026-0042', $mail->envelope()->subject);
        $this->assertStringContainsString('<title>Delivery confirmed</title>', $html);
        $this->assertStringContainsString('DR-2026-0042', $html);
        $this->assertStringContainsString('SO-2026-0042', $html);
        $this->assertStringContainsString('April 3, 2026 8:15 AM (Asia/Manila)', $html);
        $this->assertStringContainsString('Confirmed', $html);
        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString($url, $text);
        $this->assertStringContainsString('Philippine Ogami Corporation', $html);
        $this->assertStringContainsString('FCIE Complex', $html);
        $this->assertStringContainsString('cid:ogami-logo@ogami', $html);
        $this->assertStringContainsString('cid:ogami-logo-dark@ogami', $html);
        $this->assertStringNotContainsString('Original receiver name', $html);
        $this->assertStringNotContainsString('Internal delivery remarks', $html);
        $this->assertStringNotContainsString('Pending invoice processing', $html);
        $this->assertDoesNotMatchRegularExpression('/\{\{|\b(?:undefined|null)\b/i', $html);
        $this->assertLessThan(102 * 1024, strlen($html));
    }

    public function test_delivery_notice_escapes_hostile_contact_and_shows_only_existing_invoice(): void
    {
        $this->seed(SettingsSeeder::class);
        $mail = new CustomerDeliveryConfirmedMail(new Delivery, null, [], $this->snapshot([
            'customer_contact_name' => 'Aiko <script>alert(1)</script> Tanaka',
            'invoice_number' => 'INV-2026-0042',
            'delivered_at' => null,
        ]));
        $html = $mail->render();

        $this->assertStringContainsString('Hello Aiko Tanaka,', $html);
        $this->assertStringContainsString('INV-2026-0042', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('Delivered at', $html);
    }

    /** @return array<string, mixed> */
    private function snapshot(array $overrides = []): array
    {
        return array_replace([
            'delivery_id' => 42,
            'delivery_hash_id' => 'deliveryhash123',
            'delivery_number' => 'DR-2026-0042',
            'sales_order_number' => 'SO-2026-0042',
            'delivered_at' => '2026-04-03T08:15:00+08:00',
            'receiver_name' => 'Original receiver name',
            'receiver_position' => 'Receiving supervisor',
            'delivery_remarks' => 'Internal delivery remarks',
            'customer_email' => 'customer@example.test',
            'customer_name' => 'Example Customer',
            'customer_contact_name' => 'Aiko Tanaka',
            'invoice_number' => null,
        ], $overrides);
    }
}
