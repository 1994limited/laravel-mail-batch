<?php

namespace NineteenNinetyFour\MailBatch;

use Closure;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Contracts\Mail\Mailer;
use InvalidArgumentException;

class PendingBatch
{
    /**
     * The maximum number of messages per request.
     */
    protected ?int $size = null;

    /**
     * The number of milliseconds to wait between requests.
     */
    protected int $interval = 0;

    /**
     * The number of times a failed request should be attempted.
     */
    protected int $attempts = 1;

    /**
     * The number of milliseconds to wait between attempts.
     */
    protected Closure|int $retrySleep = 0;

    /**
     * Create a new pending batch instance.
     *
     * @param  array{size?: int, interval?: int, per_second?: int, retry?: int, retry_sleep?: int}  $config
     */
    public function __construct(
        protected MailBatcher $batcher,
        protected Mailer $mailer,
        protected ?string $mailerName = null,
        array $config = [],
    ) {
        if (isset($config['size'])) {
            $this->size((int) $config['size']);
        }

        if (isset($config['interval'])) {
            $this->interval((int) $config['interval']);
        }

        if (isset($config['per_second'])) {
            $this->perSecond((int) $config['per_second']);
        }

        if (isset($config['retry'])) {
            $this->retry((int) $config['retry'], $config['retry_sleep'] ?? 0);
        }
    }

    /**
     * Set the maximum number of messages per request.
     *
     * The size is capped at the maximum supported by the mailer's transport.
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function size(int $size): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('The batch size must be at least 1.');
        }

        $this->size = $size;

        return $this;
    }

    /**
     * Set the number of milliseconds to wait between requests.
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function interval(int $milliseconds): static
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('The batch interval may not be negative.');
        }

        $this->interval = $milliseconds;

        return $this;
    }

    /**
     * Limit the number of requests made per second.
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function perSecond(int $requests): static
    {
        if ($requests < 1) {
            throw new InvalidArgumentException('The number of requests per second must be at least 1.');
        }

        return $this->interval((int) ceil(1000 / $requests));
    }

    /**
     * Specify how many times a failed request should be attempted.
     *
     * Only use retries with a transport whose provider honours idempotency keys, or when
     * a message delivered twice is acceptable: a request that fails after the provider has
     * accepted it can't be told apart from one that never reached the provider.
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function retry(int $times, Closure|int $sleepMilliseconds = 0): static
    {
        if ($times < 1) {
            throw new InvalidArgumentException('The number of attempts must be at least 1.');
        }

        $this->attempts = $times;
        $this->retrySleep = $sleepMilliseconds;

        return $this;
    }

    /**
     * Send the given mailables as a batch.
     *
     * Mailables are always sent now, as part of the batch, even if they implement ShouldQueue.
     *
     * @param  iterable<Mailable>  $mailables
     *
     * @throws InvalidArgumentException
     */
    public function send(iterable $mailables): BatchResult
    {
        return $this->capture(function ($mailer) use ($mailables) {
            foreach ($mailables as $mailable) {
                if (! $mailable instanceof MailableContract) {
                    throw new InvalidArgumentException('Only mailables may be sent in a batch.');
                }

                $mailer->sendNow($mailable);
            }
        });
    }

    /**
     * Capture the messages sent with the mailer within the callback and send them as a batch.
     *
     * Only messages sent synchronously are captured. A mailable that is queued, with "queue",
     * "later", or by implementing ShouldQueue, is queued as usual and isn't part of the batch.
     */
    public function capture(Closure $callback): BatchResult
    {
        return $this->batcher->run($this, $callback);
    }

    /**
     * Get the mailer the batch is sent with.
     */
    public function getMailer(): Mailer
    {
        return $this->mailer;
    }

    /**
     * Get the name of the mailer the batch is sent with.
     */
    public function getMailerName(): ?string
    {
        return $this->mailerName;
    }

    /**
     * Get the maximum number of messages per request.
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * Get the number of milliseconds to wait between requests.
     */
    public function getInterval(): int
    {
        return $this->interval;
    }

    /**
     * Get the number of times a failed request should be attempted.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Get the number of milliseconds to wait between attempts.
     */
    public function getRetrySleep(): Closure|int
    {
        return $this->retrySleep;
    }
}
