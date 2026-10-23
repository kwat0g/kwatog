<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Mail\QueuedMailable;
use App\Common\Models\EmailDelivery;
use App\Common\Services\EmailDeliveryLogService;
use App\Modules\Auth\Notifications\PasswordResetLinkNotification;
use App\Modules\Auth\Notifications\WelcomeNotification;
use App\Modules\HR\Notifications\EmployeePasswordResetNotification;
use App\Modules\HR\Notifications\EmployeeWelcomeNotification;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Notifications\ContactInquiryReceivedNotification;
use App\Modules\Production\Notifications\DailyProductionSummary;
use App\Modules\Production\Notifications\WeeklyProductionSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailQueueConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_mailable_uses_mail_lane_and_after_commit_defaults(): void
    {
        Queue::fake();

        Mail::to('recipient@example.test')->queue(new \App\Common\Mail\EmailIntegrationTestMail);

        $this->assertDatabaseHas('email_deliveries', [
            'email_type' => \App\Common\Mail\EmailIntegrationTestMail::class,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        Queue::assertPushedOn('mail', SendQueuedMailable::class, function (SendQueuedMailable $job): bool {
            return $job->afterCommit === true
                && $job->tries === 3
                && $job->backoff() === [60, 300, 900];
        });
    }

    public function test_application_mail_uses_dedicated_after_commit_queue_with_bounded_retries(): void
    {
        $mailTypes = [
            \App\Common\Mail\EmailIntegrationTestMail::class,
            \App\Common\Mail\NotificationDigestMail::class,
            \App\Common\Mail\ScheduledExportMail::class,
            \App\Common\Mail\UserNotificationMail::class,
            \App\Modules\Accounting\Mail\CustomerInvoiceFinalizedMail::class,
            \App\Modules\Accounting\Mail\CustomerOfficialReceiptMail::class,
            \App\Modules\Accounting\Mail\InvoiceDunningMail::class,
            \App\Modules\Accounting\Mail\PartyCreditNoteMail::class,
            \App\Modules\B2B\Mail\PortalAccessInvitationMail::class,
            \App\Modules\B2B\Mail\PortalPasswordResetMail::class,
            \App\Modules\B2B\Mail\SupplierInvoiceStatusMail::class,
            \App\Modules\B2B\Mail\SupplierPurchaseOrderCancelledMail::class,
            \App\Modules\CRM\Mail\CustomerComplaintUpdateMail::class,
            \App\Modules\CRM\Mail\CustomerSalesOrderConfirmedMail::class,
            \App\Modules\CRM\Mail\CustomerSalesOrderDecisionMail::class,
            \App\Modules\HR\Mail\ApplicationReceivedMail::class,
            \App\Modules\HR\Mail\ApplicationStatusUpdatedMail::class,
            \App\Modules\HR\Mail\InterviewDetailsUpdatedMail::class,
            \App\Modules\HR\Mail\InterviewScheduledMail::class,
            \App\Modules\Purchasing\Mail\SupplierPoDecisionMail::class,
            \App\Modules\Purchasing\Mail\SupplierPurchaseOrderMail::class,
            \App\Modules\Purchasing\Mail\SupplierRfqLifecycleMail::class,
            \App\Modules\ReturnManagement\Mail\ReturnCaseUpdateMail::class,
            \App\Modules\ReturnManagement\Mail\ReturnRequestUpdateMail::class,
            \App\Modules\SupplyChain\Mail\CustomerDeliveryConfirmedMail::class,
        ];

        foreach ($mailTypes as $mailType) {
            $instance = (new \ReflectionClass($mailType))->newInstanceWithoutConstructor();

            $this->assertInstanceOf(QueuedMailable::class, $instance, $mailType.' must use the shared queue policy.');
            $this->assertSame(3, $instance->tries, $mailType.' must have bounded attempts.');
            $this->assertSame([60, 300, 900], $instance->backoff, $mailType.' must back off.');
        }

        $notifications = [
            new DailyProductionSummary([]),
            new WeeklyProductionSummary([]),
            new WelcomeNotification('temporary-secret'),
            new PasswordResetLinkNotification('https://ogami.test/reset', 15),
            new EmployeeWelcomeNotification('temporary-secret'),
            new EmployeePasswordResetNotification('temporary-secret'),
            new ContactInquiryReceivedNotification(new ContactInquiry),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertSame('mail', $notification->queue);
            $this->assertTrue($notification->afterCommit);
            $this->assertSame(3, $notification->tries);
            $this->assertSame([60, 300, 900], $notification->backoff);
        }
    }

    public function test_sent_message_is_logged_without_body(): void
    {
        config([
            'mail.default' => 'array',
            'mail.safety_recipient' => 'mailpit@example.test',
            'app.env' => 'testing',
        ]);

        Mail::mailer('array')->raw('private message body', function ($message): void {
            $message->to('recipient@example.test')->subject('Delivery check');
        });

        $delivery = EmailDelivery::query()->where('subject', 'Delivery check')->sole();

        $this->assertSame('sent', $delivery->status);
        $this->assertSame(['recipient@example.test'], $delivery->recipients['to']);
        $this->assertSame([], $delivery->recipients['cc']);
        $this->assertSame([], $delivery->recipients['bcc']);
        $this->assertStringNotContainsString('private message body', json_encode($delivery->getAttributes()));
        $this->assertNotEmpty($delivery->provider_message_id);
    }

    public function test_retry_after_ambiguous_sending_state_is_suppressed(): void
    {
        $service = app(EmailDeliveryLogService::class);
        $email = (new Email)->to('recipient@example.test')->subject('Retry check');
        $email->getHeaders()->addTextHeader('X-OGAMI-DELIVERY-ID', 'delivery-retry-test');

        $this->assertNull($service->beginMessage(new MessageSending($email)));
        $this->assertFalse($service->beginMessage(new MessageSending($email)));
        $this->assertDatabaseHas('email_deliveries', [
            'delivery_key' => 'delivery-retry-test',
            'status' => 'unknown',
            'error_code' => 'ambiguous_previous_attempt',
        ]);
    }
}
