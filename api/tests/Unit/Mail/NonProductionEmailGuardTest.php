<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Common\Services\NonProductionEmailGuard;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class NonProductionEmailGuardTest extends TestCase
{
    public function test_non_production_mail_keeps_the_account_recipient_and_marks_message(): void
    {
        config(['app.env' => 'local']);
        $message = (new Email)
            ->to('customer@example.com')
            ->cc('finance@example.com')
            ->bcc('audit@example.com')
            ->replyTo('sales@example.com')
            ->subject('Invoice issued');

        app(NonProductionEmailGuard::class)->protect($message);

        self::assertSame(['customer@example.com'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getTo(),
        ));
        self::assertSame([], $message->getCc());
        self::assertSame([], $message->getBcc());
        self::assertSame(['sales@example.com'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getReplyTo(),
        ));
        self::assertSame('[LOCAL] Invoice issued', $message->getSubject());
    }

    public function test_non_production_mail_does_not_require_a_test_sink(): void
    {
        config(['app.env' => 'local']);

        $message = (new Email)->to('customer@example.com');

        app(NonProductionEmailGuard::class)->protect($message);

        self::assertSame(['customer@example.com'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getTo(),
        ));
    }

    public function test_registered_sending_hook_preserves_recipient_before_transport(): void
    {
        config(['app.env' => 'staging']);
        $message = (new Email)->to('supplier@example.com')->subject('PO issued');

        Event::dispatch(new MessageSending($message));

        self::assertSame(['supplier@example.com'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getTo(),
        ));
        self::assertSame('[STAGING] PO issued', $message->getSubject());
    }

    public function test_production_recipient_is_not_rewritten(): void
    {
        config([
            'app.env' => 'production',
        ]);
        $message = (new Email)->to('customer@example.com')->subject('Invoice issued');

        app(NonProductionEmailGuard::class)->protect($message);

        self::assertSame(['customer@example.com'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getTo(),
        ));
        self::assertSame('Invoice issued', $message->getSubject());
    }
}
