<?php

namespace NineteenNinetyFour\MailBatch;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Testing\Fakes\MailFake;
use LogicException;
use NineteenNinetyFour\MailBatch\Capture\BatchFlusher;
use NineteenNinetyFour\MailBatch\Capture\Capture;
use NineteenNinetyFour\MailBatch\Capture\CapturingTransport;
use NineteenNinetyFour\MailBatch\Capture\Context;
use NineteenNinetyFour\MailBatch\Exceptions\BatchListenerException;
use NineteenNinetyFour\MailBatch\Testing\BatchRecorder;
use Throwable;

/**
 * @mixin BatchRecorder
 */
class MailBatcher
{
    /**
     * The contexts that have a batch open on the mail fake.
     *
     * @var array<string, true>
     */
    protected array $fakeCaptures = [];

    /**
     * Create a new mail batcher instance.
     */
    public function __construct(
        protected Container $container,
        protected ?Dispatcher $events,
        protected BatchRecorder $recorder,
    ) {
        //
    }

    /**
     * Begin a batch on the given mailer, or the default mailer.
     */
    public function mailer(?string $name = null): PendingBatch
    {
        $manager = $this->container->make('mail.manager');

        if ($manager instanceof MailFake) {
            return new PendingBatch($this, $manager, $name ?? $this->defaultMailer());
        }

        return $this->pending($manager->mailer($name), $name);
    }

    /**
     * Begin a batch on the given mailer instance.
     *
     * When mail is faked the batch is recorded by the fake instead, so nothing is sent.
     */
    public function pending(Mailer $mailer, ?string $name = null): PendingBatch
    {
        $manager = $this->container->make('mail.manager');

        // Mail::mailer('name')->batch() reaches the real mailer even when mail is faked, as the
        // fake passes calls it doesn't know on to the real mail manager. The fake remembers
        // the mailer that was asked for, so the batch is recorded against that mailer.
        if ($manager instanceof MailFake) {
            $current = Closure::bind(function () {
                [$current, $this->currentMailer] = [$this->currentMailer, null];

                return $current;
            }, $manager, MailFake::class)();

            return new PendingBatch($this, $manager, $current ?? $name ?? $this->defaultMailer());
        }

        $name ??= $this->defaultMailer();

        return new PendingBatch(
            $this, $mailer, $name,
            (array) $this->container->make('config')->get("mail.mailers.{$name}.batch", []),
        );
    }

    /**
     * Send the given mailables as a batch with the default mailer.
     *
     * @param  iterable<Mailable>  $mailables
     */
    public function send(iterable $mailables): BatchResult
    {
        return $this->mailer()->send($mailables);
    }

    /**
     * Capture the messages sent with the default mailer within the callback and send them as a batch.
     */
    public function capture(Closure $callback): BatchResult
    {
        return $this->mailer()->capture($callback);
    }

    /**
     * Capture the messages sent within the callback and send them as a batch.
     *
     * @internal
     *
     * @throws LogicException
     */
    public function run(PendingBatch $batch, Closure $callback): BatchResult
    {
        $mailer = $batch->getMailer();

        if ($mailer instanceof MailFake) {
            return $this->runFake($batch, $mailer, $callback);
        }

        /** @var Mailer $mailer */
        $transport = $this->capturingTransport($mailer);

        $capture = $transport->open();

        try {
            $callback($mailer);
        } finally {
            $transport->close($capture);

            // Once no batch is open on the mailer, in this or any other coroutine, the mailer's
            // own transport is put back, unless something else replaced it in the meantime.
            if (! $transport->hasOpenCaptures() && $mailer->getSymfonyTransport() === $transport) {
                $mailer->setSymfonyTransport($transport->getTransport());
            }
        }

        try {
            $result = (new BatchFlusher($this->events))->flush($batch, $transport->getTransport(), $capture->messages());
        } catch (BatchListenerException $e) {
            $capture->sent($e->result);

            throw $e;
        }

        if ($exception = $capture->sent($result)) {
            throw $exception;
        }

        return $result;
    }

    /**
     * Get the capture open on the given mailer for the current coroutine or fiber, if any.
     *
     * @internal
     */
    public function currentCapture(Mailer $mailer): ?Capture
    {
        $transport = $mailer->getSymfonyTransport();

        return $transport instanceof CapturingTransport ? $transport->current() : null;
    }

    /**
     * Determine if a batch is open on the given mailer for the current coroutine or fiber.
     */
    public function capturing(Mailer $mailer): bool
    {
        return $this->capturedCount($mailer) !== null;
    }

    /**
     * Get the number of messages captured by the batch open on the given mailer, if any.
     *
     * @internal
     */
    public function capturedCount(Mailer $mailer): ?int
    {
        $transport = $mailer->getSymfonyTransport();

        return $transport instanceof CapturingTransport
            ? $transport->current()?->count()
            : null;
    }

    /**
     * Wrap the mailer's transport in a capturing transport, unless it is already wrapped.
     */
    protected function capturingTransport(Mailer $mailer): CapturingTransport
    {
        $transport = $mailer->getSymfonyTransport();

        if (! $transport instanceof CapturingTransport) {
            $mailer->setSymfonyTransport($transport = new CapturingTransport($transport));
        }

        return $transport;
    }

    /**
     * Record the mailables sent within the callback as a batch on the mail fake.
     */
    protected function runFake(PendingBatch $batch, MailFake $fake, Closure $callback): BatchResult
    {
        $mailables = Closure::bind(function (?int $length = null) {
            if (! is_null($length)) {
                $this->mailables = array_slice($this->mailables, 0, $length);
            }

            return $this->mailables;
        }, $fake, MailFake::class);

        $context = Context::key();

        if (isset($this->fakeCaptures[$context])) {
            throw new LogicException('A batch is already in progress on this mailer.');
        }

        $this->fakeCaptures[$context] = true;

        $before = count($mailables());

        try {
            $callback($fake);
        } catch (Throwable $e) {
            // Like the real mailer, nothing captured by a batch whose callback throws is sent.
            $mailables($before);

            throw $e;
        } finally {
            unset($this->fakeCaptures[$context]);
        }

        $name = $batch->getMailerName();

        // Like the real mailer, only mail sent with the batch's mailer is part of the batch.
        // Mail sent with another mailer is recorded by the fake as an ordinary send.
        $this->recorder->record($name, array_values(array_filter(
            array_slice($mailables(), $before),
            fn ($mailable) => in_array($mailable->mailer ?? null, [null, $name], true)
                || ($mailable->mailer === $this->defaultMailer() && $name === $this->defaultMailer()),
        )));

        return new BatchResult;
    }

    /**
     * Get the name of the default mailer.
     */
    protected function defaultMailer(): ?string
    {
        return $this->container->make('config')->get('mail.default');
    }

    /**
     * Get the recorder of the batches sent while mail is faked.
     */
    public function recorder(): BatchRecorder
    {
        return $this->recorder;
    }

    /**
     * Pass assertion calls through to the recorder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->recorder->{$method}(...$parameters);
    }
}
