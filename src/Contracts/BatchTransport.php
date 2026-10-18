<?php

namespace NineteenNinetyFour\MailBatch\Contracts;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * A transport that is able to send several messages in a single request.
 *
 * Batch requests are made by calling "sendBatch" directly rather than the transport's
 * "send" method, so Symfony's MessageEvent listeners and "setMaxPerSecond" throttle do
 * not apply to them. Batches are paced using the batch's own interval instead.
 */
interface BatchTransport
{
    /**
     * Get the maximum number of messages that may be sent in a single request.
     */
    public function maxBatchSize(): int;

    /**
     * Determine if the given message may be sent as part of a batch request.
     */
    public function canBatch(Email $email): bool;

    /**
     * Send the given messages in a single request.
     *
     * Failures of individual messages are returned, keyed like the given messages. Any
     * message that is not returned as a failure is considered sent. An exception should
     * only be thrown when the request as a whole failed and no message has been sent.
     *
     * The "idempotency_key" option is the same for every attempt of a request, so that a
     * transport whose provider supports it can make retried requests safe to repeat.
     *
     * @param  array<int, SentMessage>  $messages
     * @param  array{idempotency_key?: string}  $options
     * @return array<int, \Throwable>
     *
     * @throws \Throwable
     */
    public function sendBatch(array $messages, array $options = []): array;
}
