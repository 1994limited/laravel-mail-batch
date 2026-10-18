<?php

namespace NineteenNinetyFour\MailBatch\Attributes;

use Attribute;

/**
 * Configures how a notification's mail is batched when it's sent with Notification::batch().
 *
 * The attribute doesn't make Notification::send() or $user->notify() batch the mail.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class BatchMail
{
    /**
     * Create a new attribute instance.
     *
     * Options left null fall back to the mailer's "batch" configuration.
     *
     * @param  int|null  $size  The maximum number of messages per request.
     * @param  int|null  $interval  The number of milliseconds to wait between requests.
     * @param  int|null  $perSecond  The maximum number of requests per second.
     * @param  string|null  $mailer  The mailer that sends the notification's mail.
     */
    public function __construct(
        public ?int $size = null,
        public ?int $interval = null,
        public ?int $perSecond = null,
        public ?string $mailer = null,
    ) {
        //
    }
}
