<?php

namespace NineteenNinetyFour\MailBatch\Capture;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

class CapturedMessage
{
    /**
     * Create a new captured message instance.
     */
    public function __construct(
        public readonly Email $message,
        public readonly Envelope $envelope,
        public readonly array $data,
        public readonly ?string $locale,
    ) {
        //
    }
}
