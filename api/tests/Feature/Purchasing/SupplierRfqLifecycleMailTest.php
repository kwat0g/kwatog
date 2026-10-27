<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Mail\SupplierRfqLifecycleMail;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

final class SupplierRfqLifecycleMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_failure_fallback_is_gated_by_the_rfq_subject(): void
    {
        $rfq = RequestForQuote::factory()->create(['rfq_number' => 'RFQ-2026-0032']);
        $invitation = (new RequestForQuoteInvitation)->forceFill([
            'request_for_quote_id' => $rfq->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'invited_by' => $rfq->created_by,
            'invited_at' => now(),
            'status' => 'invited',
        ]);
        $invitation->save();
        $user = User::factory()->create(['is_active' => true]);

        $notifier = \Mockery::mock(EmailDeliveryFailureNotifier::class);
        $notifier->shouldReceive('notifyUserIds')->once()->withArgs(
            function (array $ids, string $feature, string $message, array $context, ?RequestForQuote $subject) use ($rfq, $user): bool {
                $this->assertSame([$user->id], $ids);
                $this->assertSame('Supplier RFQ', $feature);
                $this->assertSame('Supplier RFQ RFQ-2026-0032 email delivery failed; review portal delivery state.', $message);
                $this->assertSame('/purchasing/rfqs/'.$rfq->hash_id, $context['link_to']);
                $this->assertTrue($subject?->is($rfq));

                return true;
            },
        );
        $this->app->instance(EmailDeliveryFailureNotifier::class, $notifier);

        (new SupplierRfqLifecycleMail($rfq, $invitation, 'published', [$user->id]))
            ->failed(new \RuntimeException('Mail delivery failed.'));
    }

    public function test_lifecycle_variants_are_branded_actionable_private_and_accurate(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test', 'app.timezone' => 'UTC']);

        $rfq = RequestForQuote::factory()->create([
            'rfq_number' => 'RFQ-2026-0032',
            'title' => "Resin lot <script>alert('x')</script> & mold",
            'closes_at' => '2026-11-30T01:30:00Z',
        ]);
        $vendor = Vendor::factory()->create([
            'name' => 'Mabuhay Supply',
            'contact_person' => '<img src=x onerror=alert(1)>Aiko Tanaka',
            'email' => 'supplier@example.test',
        ]);
        $invitation = (new RequestForQuoteInvitation)->forceFill([
            'request_for_quote_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'invited_by' => $rfq->created_by,
            'invited_at' => now(),
            'status' => 'invited',
        ]);
        $invitation->save();
        $base = [
            'rfq_number' => 'RFQ-2026-0032',
            'rfq_hash_id' => $rfq->hash_id,
            'title' => $rfq->title,
            'closes_at' => '2026-11-30T01:30:00+00:00',
            'contact_person' => $vendor->contact_person,
            'vendor_name' => $vendor->name,
        ];

        $cases = [
            ['published', 'invited', 'Open for quotation', 'Review the request and submit your quotation by the deadline.'],
            ['extended', 'invited', 'Deadline extended', 'Submit your quotation by the revised deadline.'],
            ['awarded', 'awarded', 'Selected', 'Your quotation was selected for one or more lines.'],
            ['awarded', 'not_awarded', 'Not selected', 'It was not selected for this request.'],
            ['cancelled', 'invited', 'Cancelled', 'No award will be made for this request.'],
        ];

        foreach ($cases as [$kind, $status, $statusLabel, $message]) {
            $mail = new SupplierRfqLifecycleMail($rfq, $invitation, $kind, [], [...$base, 'status' => $status]);
            $content = $mail->content();
            $html = $mail->render();
            $text = (string) app(Markdown::class)->renderText($content->markdown, $content->with);
            $previewDirectory = getenv('EMAIL_PREVIEW_DIR');
            if (is_string($previewDirectory) && trim($previewDirectory) !== '') {
                if (! is_dir($previewDirectory)) {
                    mkdir($previewDirectory, 0777, true);
                }
                file_put_contents($previewDirectory.'/'.$kind.'-'.$status.'.html', $html);
            }

            $this->assertStringStartsWith('[Ogami Philippines]', $mail->envelope()->subject);
            $this->assertStringContainsString($statusLabel, $html);
            $this->assertStringContainsString($message, html_entity_decode(strip_tags($html)));
            if (in_array($kind, ['published', 'extended'], true)) {
                $this->assertStringContainsString('Nov 30, 2026 9:30 AM Philippine time', $html);
            }
            $this->assertStringContainsString('https://erp.example.test/portal/supplier/rfqs/'.$rfq->hash_id, $html);
            $this->assertStringContainsString('https://erp.example.test/portal/supplier/rfqs/'.$rfq->hash_id, $text);
            $this->assertStringContainsString('Philippine Ogami Corporation', $html);
            $this->assertStringContainsString('FCIE Complex', $html);
            $this->assertStringNotContainsString('<script', strtolower($html));
            $this->assertStringNotContainsString('onerror=', strtolower($html));
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $mail->envelope()->subject);
            $this->assertStringNotContainsString('alert(\'x\')', $html);
            $this->assertStringNotContainsString('{{', $html);
            $this->assertDoesNotMatchRegularExpression('/\b(?:undefined|null)\b/i', $html);
            $this->assertLessThan(102 * 1024, strlen($html));
        }

        $hostileHeaderMail = new SupplierRfqLifecycleMail($rfq, $invitation, 'published', [], [
            ...$base,
            'rfq_number' => "RFQ-2026-0032\r\nBcc: attacker@example.test",
        ]);
        $this->assertSame(
            '[Ogami Philippines] RFQ invitation: RFQ-2026-0032 Bcc: attacker@example.test',
            $hostileHeaderMail->envelope()->subject,
        );
    }
}
