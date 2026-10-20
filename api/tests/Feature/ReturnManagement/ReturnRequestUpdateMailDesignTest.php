<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\ReturnManagement\Mail\ReturnRequestUpdateMail;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

final class ReturnRequestUpdateMailDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_supplier_updates_are_branded_private_and_actionable(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);

        foreach ([
            ['customer_return', 'customer', 'approved', 'Your return request was approved.'],
            ['supplier_return', 'supplier', 'supplier return sent', 'The supplier return was sent for processing.'],
        ] as [$type, $portal, $action, $message]) {
            $mail = new ReturnRequestUpdateMail(new ReturnRequest, $action, [], [
                'rma_number' => 'RMA-2026-0042',
                'hash_id' => 'rmahash123',
                'type' => $type,
                'type_label' => $portal === 'customer' ? 'Customer Return' : 'Supplier Return',
                'status_label' => $portal === 'supplier' ? 'Completed' : 'Approved',
                'action' => $action,
                'party_name' => 'Example Company',
                'party_contact_name' => 'Aiko <img src=x onerror=alert(1)> Tanaka',
                'refund_amount' => '987654321098765.99',
                'reason_description' => 'Confidential reason text',
                'resolution' => 'credit_note',
            ], 'stable-delivery-id');
            $content = $mail->content();
            $html = $mail->render();
            $text = (string) app(Markdown::class)->renderText($content->markdown, $content->with);
            $previewDirectory = getenv('EMAIL_PREVIEW_DIR');
            if (is_string($previewDirectory) && trim($previewDirectory) !== '') {
                if (! is_dir($previewDirectory)) {
                    mkdir($previewDirectory, 0777, true);
                }
                file_put_contents($previewDirectory.'/return-request-'.$portal.'.html', $html);
            }
            $expectedUrl = $portal === 'customer'
                ? 'https://erp.example.test/portal/customer/returns/rmahash123'
                : 'https://erp.example.test/portal/supplier';

            $this->assertSame('[Ogami Philippines] Return request update: RMA-2026-0042', $mail->envelope()->subject);
            $this->assertStringContainsString($message, html_entity_decode(strip_tags($html)));
            $this->assertStringContainsString('RMA-2026-0042', $html);
            $this->assertStringContainsString($portal === 'supplier' ? 'Completed' : 'Approved', $html);
            $this->assertStringContainsString($expectedUrl, $html);
            $this->assertStringContainsString($expectedUrl, $text);
            $this->assertStringContainsString('Philippine Ogami Corporation', $html);
            $this->assertStringContainsString('FCIE Complex', $html);
            $this->assertStringNotContainsString('Confidential reason text', $html);
            $this->assertStringNotContainsString('credit_note', $html);
            $this->assertStringNotContainsString('987654321098765.99', $html);
            $this->assertStringNotContainsString('onerror=', strtolower($html));
            $this->assertStringNotContainsString('src=x', strtolower($html));
            $this->assertStringNotContainsString('{{', $html);
            $this->assertDoesNotMatchRegularExpression('/\b(?:undefined|null)\b/i', $html);
            $this->assertLessThan(102 * 1024, strlen($html));

        }
    }

    public function test_all_known_return_request_actions_use_reader_facing_copy(): void
    {
        $this->seed(SettingsSeeder::class);
        $actions = [
            'submitted for approval' => 'Your return request was submitted for review.',
            'supplier return sent' => 'The supplier return was sent for processing.',
            'approved' => 'Your return request was approved.',
            'received' => 'The returned items were recorded as received.',
            'partial receipt recorded' => 'A partial receipt was recorded for this return.',
            'inspection completed' => 'Inspection was completed for this return.',
            'return line split' => 'The return quantity allocation was updated.',
            'disposition recorded' => 'The returned items’ disposition was recorded.',
            'completed' => 'The return request was completed.',
            'rejected' => 'The return request was rejected.',
            'cancelled' => 'The return request was cancelled.',
            'unknown-action' => 'Your return request record was updated.',
        ];

        foreach ($actions as $action => $message) {
            $mail = new ReturnRequestUpdateMail(new ReturnRequest, $action, [], [
                'rma_number' => 'RMA-2026-0042',
                'type' => 'customer_return',
                'status_label' => 'Approved',
                'action' => $action,
            ]);
            $this->assertSame($message, $mail->content()->with['notice']['message']);
        }
    }
}
