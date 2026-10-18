<?php

namespace NineteenNinetyFour\MailBatch\Exceptions;

use NineteenNinetyFour\MailBatch\BatchResult;
use RuntimeException;
use Throwable;

/**
 * Thrown when a MessageSent listener throws while a batch is sent.
 *
 * Every request of the batch is still made and every sent event is still dispatched,
 * so the result describes the whole batch. The listener's exception is the previous one.
 */
class BatchListenerException extends RuntimeException
{
    /**
     * Create a new batch listener exception instance.
     */
    public function __construct(public readonly BatchResult $result, Throwable $previous)
    {
        parent::__construct(
            'A listener threw while the batch was sent: '.$previous->getMessage(),
            0,
            $previous,
        );
    }
}
