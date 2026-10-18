<?php

namespace NineteenNinetyFour\MailBatch\Exceptions;

use NineteenNinetyFour\MailBatch\BatchResult;
use RuntimeException;

class BatchSendException extends RuntimeException
{
    /**
     * Create a new batch send exception instance.
     */
    public function __construct(public readonly BatchResult $result)
    {
        $failures = $result->failures();

        parent::__construct(
            sprintf('%d of %d messages in the batch failed to send.', count($failures), count($result)),
            0,
            reset($failures) ?: null,
        );
    }
}
