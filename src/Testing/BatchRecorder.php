<?php

namespace NineteenNinetyFour\MailBatch\Testing;

use Closure;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records the batches sent while mail is faked with Mail::fake().
 */
class BatchRecorder
{
    /**
     * The recorded batches.
     *
     * @var array<int, array{mailer: string|null, mailables: array<int, Mailable>}>
     */
    protected array $batches = [];

    /**
     * Record a batch.
     *
     * @param  array<int, Mailable>  $mailables
     */
    public function record(?string $mailer, array $mailables): void
    {
        $this->batches[] = ['mailer' => $mailer, 'mailables' => $mailables];
    }

    /**
     * Get the recorded batches.
     *
     * @return array<int, array{mailer: string|null, mailables: array<int, Mailable>}>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    /**
     * Forget every recorded batch.
     */
    public function flush(): void
    {
        $this->batches = [];
    }

    /**
     * Assert that a batch was sent, optionally matching the given callback.
     *
     * The callback receives the batch's mailables as a collection and the mailer's name.
     */
    public function assertBatched(?Closure $callback = null): void
    {
        $matching = array_filter($this->batches, fn ($batch) => is_null($callback)
            || $callback(new Collection($batch['mailables']), $batch['mailer']) === true);

        PHPUnit::assertNotEmpty($matching, 'The expected mail batch was not sent.');
    }

    /**
     * Assert that no batch was sent.
     */
    public function assertNotBatched(): void
    {
        PHPUnit::assertEmpty($this->batches, 'Mail batches were sent unexpectedly.');
    }

    /**
     * Assert the number of batches that were sent.
     */
    public function assertBatchCount(int $count): void
    {
        PHPUnit::assertCount(
            $count, $this->batches,
            "The number of mail batches sent was not {$count}.",
        );
    }
}
