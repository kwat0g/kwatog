<?php

declare(strict_types=1);

namespace App\Common\Services;

use RuntimeException;
use Symfony\Component\Mime\Email;

class NonProductionEmailGuard
{
    public function protect(Email $message): void
    {
        $environment = (string) config('app.env', 'production');
        if ($environment === 'production') {
            return;
        }

        $recipient = config('mail.safety_recipient');
        if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Outbound email blocked: configure a valid MAIL_TEST_TO recipient outside production.');
        }

        foreach (['To', 'Cc', 'Bcc', 'Reply-To'] as $header) {
            $message->getHeaders()->remove($header);
        }

        $message->to($recipient)->replyTo($recipient);

        $prefix = '['.strtoupper($environment).'] ';
        $subject = $message->getSubject() ?? '';
        if (! str_starts_with($subject, $prefix)) {
            $message->subject($prefix.$subject);
        }
    }
}
