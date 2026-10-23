<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Common\Services\NonProductionEmailGuard;
use RuntimeException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class NonProductionEmailGuardTest extends TestCase
{
    public function test_non_production_mail_is_redirected_and_marked(): void
    {
        config([
            'app.env' => 'local',
            'mail.safety_recipient' => 'mailpit@example.test',
        ]);
        $message = (new Email)
            ->to('customer@example.com')
            ->cc('finance@example.com')
            ->bcc('audit@example.com')
            ->replyTo('sales@example.com')
            ->subject('Invoice issued');

        app(NonProductionEmailGuard::class)->protect($message);

        self::assertSame(['mailpit@example.test'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getTo(),
        ));
        self::assertSame([], $message->getCc());
        self::assertSame([], $message->getBcc());
        self::assertSame(['mailpit@example.test'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getReplyTo(),
        ));
        self::assertSame('[LOCAL] Invoice issued', $message->getSubject());
    }

    public function test_non_production_mail_fails_closed_without_valid_sink(): void
    {
        config([
            'app.env' => 'local',
            'mail.safety_recipient' => null,
        ]);

        $this->expectException(RuntimeException::class);
        app(NonProductionEmailGuard::class)->protect((new Email)->to('customer@example.com'));
    }

    public function test_registered_sending_hook_redirects_before_transport(): void
    {
        config([
            'app.env' => 'staging',
            'mail.safety_recipient' => 'mailpit@example.test',
        ]);
        $message = (new Email)->to('supplier@example.com')->subject('PO issued');

        Event::dispatch(new MessageSending($message));

        self::assertSame(['mailpit@example.test'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getTo(),
        ));
        self::assertSame('[STAGING] PO issued', $message->getSubject());
    }

    public function test_production_recipient_is_not_rewritten(): void
    {
        config([
            'app.env' => 'production',
            'mail.safety_recipient' => 'mailpit@example.test',
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
