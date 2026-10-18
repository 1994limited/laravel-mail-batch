<?php

namespace NineteenNinetyFour\MailBatch\Tests\Fixtures;

use NineteenNinetyFour\MailBatch\Contracts\BatchTransport;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class RecordingBatchTransport extends AbstractTransport implements BatchTransport
{
    /** @var array<int, array<int, string>> */
    public array $batches = [];

    /** @var array<int, string> */
    public array $singles = [];

    /** @var array<int, array> */
    public array $options = [];

    /** @var array<int, string> */
    public array $rejected = [];

    /** @var array<int, string> */
    public array $unbatchable = [];

    public int $max = 100;

    public int $failRequests = 0;

    public $onBatch;

    protected function doSend(SentMessage $message): void
    {
        $recipient = $this->recipient($message);

        if ($this->failRequests > 0) {
            $this->failRequests--;

            throw new RuntimeException('Request failed.');
        }

        $this->singles[] = $recipient;

        $message->setMessageId("id-{$recipient}");
    }

    public function maxBatchSize(): int
    {
        return $this->max;
    }

    public function canBatch(Email $email): bool
    {
        return ! in_array($email->getTo()[0]->getAddress(), $this->unbatchable, true);
    }

    public function sendBatch(array $messages, array $options = []): array
    {
        if ($this->onBatch) {
            ($this->onBatch)($messages);
        }

        $this->options[] = $options;

        if ($this->failRequests > 0) {
            $this->failRequests--;

            throw new RuntimeException('Request failed.');
        }

        $this->batches[] = array_values(array_map(fn ($message) => $this->recipient($message), $messages));

        $failures = [];

        foreach ($messages as $index => $message) {
            $recipient = $this->recipient($message);

            if (in_array($recipient, $this->rejected, true)) {
                $failures[$index] = new RuntimeException("Rejected {$recipient}.");
            } else {
                $message->setMessageId("id-{$recipient}");
            }
        }

        return $failures;
    }

    protected function recipient(SentMessage $message): string
    {
        return $message->getEnvelope()->getRecipients()[0]->getAddress();
    }

    public function __toString(): string
    {
        return 'recording';
    }
}
