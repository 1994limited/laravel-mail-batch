<?php

namespace NineteenNinetyFour\MailBatch\Capture;

class Capture
{
    /**
     * The messages captured so far, in the order they were captured in.
     *
     * @var array<int, CapturedMessage>
     */
    protected array $messages = [];

    /**
     * Add a message to the capture.
     */
    public function add(CapturedMessage $message): void
    {
        $this->messages[] = $message;
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
