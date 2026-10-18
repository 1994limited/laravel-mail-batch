<?php

namespace NineteenNinetyFour\MailBatch\Capture;

use NineteenNinetyFour\MailBatch\BatchResult;

class Capture
{
    /**
     * The messages captured so far, in the order they were captured in.
     *
     * @var array<int, CapturedMessage>
     */
    protected array $messages = [];

    /**
     * The callbacks to call with the result once the captured messages have been sent.
     *
     * @var array<int, \Closure(BatchResult): void>
     */
    protected array $afterSending = [];

    /**
     * Add a message to the capture.
     */
    public function add(CapturedMessage $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Register a callback to call with the result once the captured messages have been sent.
     *
     * This lets code that sends mail within a batch it didn't open, such as a notification
     * batch within Mail::batch()->capture(), learn what became of its messages.
     */
    public function afterSending(\Closure $callback): void
    {
        $this->afterSending[] = $callback;
    }

    /**
     * Call the callbacks registered to receive the result.
     *
     * Every callback is called, even when one throws. The first exception is returned.
     */
    public function sent(BatchResult $result): ?\Throwable
    {
        $exception = null;

        foreach ($this->afterSending as $callback) {
            try {
                $callback($result);
            } catch (\Throwable $e) {
                $exception ??= $e;
            }
        }

        return $exception;
    }

    /**
     * Get the captured messages.
     *
     * @return array<int, CapturedMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Get the number of captured messages.
     */
    public function count(): int
    {
        return count($this->messages);
    }
}
