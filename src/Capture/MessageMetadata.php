<?php

namespace NineteenNinetyFour\MailBatch\Capture;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;
use WeakMap;

/**
 * Keeps the view data and locale of messages that may be captured by a batch.
 *
 * Laravel passes a message's data to the MessageSending event but not to the transport,
 * so it's recorded here and taken back by the capturing transport when, and only when,
 * that exact message reaches it. A message that's cancelled is never taken back, and
 * its entry is dropped along with the message.
 */
class MessageMetadata
{
    /**
     * The data and locale of each message, keyed by the message.
     *
     * @var WeakMap<Email, array{0: array, 1: string|null}>|null
     */
    protected static ?WeakMap $messages = null;

    /**
     * Record the data of a message that is about to be sent.
     */
    public static function record(MessageSending $event): void
    {
        if (! CapturingTransport::capturing() || ! $event->message instanceof Email) {
            return;
        }

        static::$messages ??= new WeakMap;

        static::$messages[$event->message] = [$event->data, static::currentLocale()];
    }

    /**
     * Take the recorded data and locale of the given message.
     *
     * @return array{0: array, 1: string|null}
     */
    public static function pull(Email $message): array
    {
        if (! isset(static::$messages[$message])) {
            return [[], static::currentLocale()];
        }

        $metadata = static::$messages[$message];

        unset(static::$messages[$message]);

        return $metadata;
    }

    /**
     * Get the application's current locale.
     */
    protected static function currentLocale(): ?string
    {
        $container = Container::getInstance();

        return $container instanceof Application ? $container->getLocale() : null;
    }
}
