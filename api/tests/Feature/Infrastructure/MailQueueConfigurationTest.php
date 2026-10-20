<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Mail\QueuedMailable;
use App\Modules\Auth\Notifications\PasswordResetLinkNotification;
use App\Modules\Auth\Notifications\WelcomeNotification;
use App\Modules\HR\Notifications\EmployeePasswordResetNotification;
use App\Modules\HR\Notifications\EmployeeWelcomeNotification;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Notifications\ContactInquiryReceivedNotification;
use App\Modules\Production\Notifications\DailyProductionSummary;
use App\Modules\Production\Notifications\WeeklyProductionSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailQueueConfigurationTest extends TestCase
{
    public function test_queued_mailable_uses_mail_lane_and_after_commit_defaults(): void
    {
        Queue::fake();

        Mail::to('recipient@example.test')->queue(new \App\Common\Mail\EmailIntegrationTestMail);

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

            if ($instance instanceof QueuedMailable) {
                (new \ReflectionMethod(QueuedMailable::class, 'applyMailQueueDefaults'))->invoke($instance);
            }

            $this->assertSame('mail', $instance->queue, $mailType.' must use the mail queue.');
            $this->assertTrue($instance->afterCommit, $mailType.' must wait for commit.');
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
}
