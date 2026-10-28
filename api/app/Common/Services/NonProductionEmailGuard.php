<?php

declare(strict_types=1);

namespace App\Common\Services;

use Symfony\Component\Mime\Email;

class NonProductionEmailGuard
{
    public function protect(Email $message): void
    {
        $environment = (string) config('app.env', 'production');
        if ($environment === 'production') {
            return;
        }

        foreach (['Cc', 'Bcc'] as $header) {
            $message->getHeaders()->remove($header);
        }

        $prefix = '['.strtoupper($environment).'] ';
        $subject = $message->getSubject() ?? '';
        if (! str_starts_with($subject, $prefix)) {
            $message->subject($prefix.$subject);
        }
    }
}
