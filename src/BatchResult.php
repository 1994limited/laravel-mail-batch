<?php

namespace NineteenNinetyFour\MailBatch;

use Countable;
use Illuminate\Mail\SentMessage;
use NineteenNinetyFour\MailBatch\Exceptions\BatchSendException;

class BatchResult implements Countable
{
    /**
     * Create a new batch result instance.
     *
     * @param  array<int, SentMessage>  $sent
     * @param  array<int, \Throwable>  $failures
     * @param  array<int, int>  $declined
     */
    public function __construct(
        protected array $sent = [],
        protected array $failures = [],
        protected int $requests = 0,
        protected array $declined = [],
    ) {
        //
    }

    /**
     * Get the messages that were sent, keyed by the order they were captured in.
     *
     * @return array<int, SentMessage>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * Get the failures, keyed by the order the messages were captured in.
     *
     * @return array<int, \Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Get the indexes of the messages the transport declined to send without an error.
     *
     * A Symfony transport returns no sent message when, for example, a MessageEvent listener
     * rejects the message. Laravel treats such a message as neither sent nor failed.
     *
     * @return array<int, int>
     */
    public function declined(): array
    {
        return $this->declined;
    }

    /**
     * Get a copy of the result with the given failures in place of its own.
     *
     * @param  array<int, \Throwable>  $failures
     */
    public function withFailures(array $failures): static
    {
        return new static($this->sent, $failures, $this->requests, $this->declined);
    }

    /**
     * Determine if every message in the batch was sent.
     */
    public function successful(): bool
    {
        return $this->failures === [];
    }

    /**
     * Determine if any message in the batch failed to send.
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Get the number of requests made to the transport.
     */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Throw an exception if any message in the batch failed to send.
     *
     * @return $this
     *
     * @throws BatchSendException
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new BatchSendException($this);
        }

        return $this;
    }

    /**
     * Get the number of messages in the batch.
     */
    public function count(): int
    {
        return count($this->sent) + count($this->failures) + count($this->declined);
    }
}
