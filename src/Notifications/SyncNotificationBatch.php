<?php

namespace NineteenNinetyFour\MailBatch\Notifications;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Events\NotificationSkipped;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Traits\Localizable;
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;
use NineteenNinetyFour\MailBatch\BatchResult;
use NineteenNinetyFour\MailBatch\Exceptions\BatchListenerException;
use NineteenNinetyFour\MailBatch\Exceptions\BatchSendException;
use NineteenNinetyFour\MailBatch\MailBatcher;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

/**
 * Sends a notification to many notifiables now, capturing its mail in a single batch.
 *
 * The notification is sent to each notifiable on each channel as Laravel's own notification
 * sender would. The mail channel's NotificationSent and NotificationFailed events, and the
 * notification's "afterSending" hook, are dispatched once the batch has been sent, with
 * each message's result matched back to its notifiable by the order it was captured in.
 */
class SyncNotificationBatch
{
    use Localizable;

    /**
     * The notification's batch configuration.
     */
    protected ?BatchMail $attribute = null;

    /**
     * Indicates if mail is faked.
     */
    protected bool $faked = false;

    /**
     * The mailer whose batch captures the notification's mail, while the batch is open.
     */
    protected ?Mailer $mailer = null;

    /**
     * The mail channel used in place of the stock mail channel.
     */
    protected ?BatchMailChannel $mailChannel = null;

    /**
     * The notifications whose mail has been captured, with the indexes of their messages.
     *
     * @var array<int, array{0: mixed, 1: mixed, 2: mixed, 3: array<int, int>}>
     */
    protected array $batched = [];

    /**
     * Create a new synchronous notification batch.
     */
    public function __construct(
        protected NotificationBatcher $batcher,
        protected ChannelManager $manager,
        protected Dispatcher $events,
        protected MailBatcher $mail,
        protected ?string $locale = null,
    ) {
        //
    }

    /**
     * Send the notification to the notifiables.
     *
     * @param  mixed  $notifiables
     * @param  mixed  $notification
     * @param  array<array-key, string>  $ids
     *
     * @throws Throwable
     */
    public function send($notifiables, $notification, ?array $channels = null, array $ids = [], ?string $idempotencyKey = null): void
    {
        $notifiables = NotificationBatcher::formatNotifiables($notifiables);

        $original = clone $notification;

        $this->attribute = $this->batcher->attribute($original);

        $manager = $this->batcher->mailManager();

        $this->faked = $manager instanceof MailFake;

        $mailer = $manager->mailer($this->attribute?->mailer);

        // When mail is faked the notification is sent as Laravel would send it, and its mail
        // is recorded by the fake. Nothing is batched, so nothing reaches a real transport.
        if (! $mailer instanceof Mailer) {
            $this->sendToNotifiables($notifiables, $original, $channels, $ids);

            return;
        }

        if ($capture = $this->mail->currentCapture($mailer)) {
            $this->sendWithinOpenBatch($capture, $mailer, $notifiables, $original, $channels, $ids);

            return;
        }

        $batch = $this->batcher->configure(
            $this->mail->pending($mailer, $this->attribute?->mailer), $this->attribute,
        )->idempotencyKey($idempotencyKey);

        $exception = $listenerException = null;

        try {
            // An exception thrown while sending stops the remaining notifiables from being sent
            // to, as it would without a batch. The mail captured before it is still delivered
            // so that every notifiable that was handled gets its mail and its sent events.
            $result = $batch->capture(function () use ($mailer, $notifiables, $original, $channels, $ids, &$exception) {
                $this->mailer = $mailer;

                try {
                    $this->sendToNotifiables($notifiables, $original, $channels, $ids);
                } catch (Throwable $e) {
                    $exception = $e;
                } finally {
                    $this->mailer = null;
                }
            });
        } catch (BatchListenerException $e) {
            $result = $e->result;

            $listenerException = $e;
        }

        $eventException = $this->dispatchBatchedEvents($this->takeBatched(), $result);

        if ($thrown = $exception ?? $listenerException ?? $eventException) {
            throw $thrown;
        }

        // When none of the messages could be sent, such as when the provider is unavailable,
        // the batch is failed as a whole so a queued job is retried. A partially sent batch
        // isn't, as retrying it would send the delivered messages a second time.
        if ($result->failed() && $result->sent() === [] && $result->declined() === []) {
            throw new BatchSendException($result->withFailures(
                array_map($this->normalizeException(...), $result->failures())
            ));
        }
    }

    /**
     * Send the notification within a batch that is already open on the mailer.
     *
     * The mail joins that batch, and the sent and failed events are dispatched once the
     * batch that's open has been sent, by whatever opened it.
     *
     * @param  Collection|array  $notifiables
     * @param  mixed  $original
     * @param  array<array-key, string>  $ids
     */
    protected function sendWithinOpenBatch($capture, Mailer $mailer, $notifiables, $original, ?array $channels, array $ids): void
    {
        $this->mailer = $mailer;

        try {
            $this->sendToNotifiables($notifiables, $original, $channels, $ids);
        } finally {
            $this->mailer = null;

            if (($batched = $this->takeBatched()) !== []) {
                $capture->afterSending(function (BatchResult $result) use ($batched) {
                    if ($exception = $this->dispatchBatchedEvents($batched, $result)) {
                        throw $exception;
                    }
                });
            }
        }
    }

    /**
     * Send the notification to each of the notifiables.
     *
     * @param  Collection|array  $notifiables
     * @param  mixed  $original
     * @param  array<array-key, string>  $ids
     */
    protected function sendToNotifiables($notifiables, $original, ?array $channels, array $ids): void
    {
        foreach ($notifiables as $key => $notifiable) {
            if (empty($viaChannels = $channels ?: $original->via($notifiable))) {
                continue;
            }

            $notificationId = $ids[$key] ?? (string) Str::uuid();

            $this->withLocale($this->preferredLocale($notifiable, $original), function () use ($viaChannels, $notifiable, $original, $notificationId) {
                foreach ((array) $viaChannels as $channel) {
                    if (! ($notifiable instanceof AnonymousNotifiable && $channel === 'database')) {
                        $this->sendToNotifiable($notifiable, $notificationId, clone $original, $channel);
                    }
                }
            });
        }
    }

    /**
     * Send the notification to the notifiable via a channel.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     * @param  mixed  $channel
     *
     * @throws Throwable
     */
    protected function sendToNotifiable($notifiable, string $id, $notification, $channel): void
    {
        if (! $notification->id) {
            $notification->id = $id;
        }

        if (! $this->shouldSendNotification($notifiable, $notification, $channel)) {
            $this->events->dispatch(
                new NotificationSkipped($notifiable, $notification, $channel)
            );

            return;
        }

        try {
            $driver = $this->driver($channel);

            // Only the mail channel's own mail is matched to the notification. Mail that any
            // other channel sends through the batch's mailer is sent with the batch alone.
            $captured = $driver instanceof MailChannel && $this->mailer
                ? $this->mail->capturedCount($this->mailer)
                : null;

            $response = $driver->send($notifiable, $notification);
        } catch (Throwable $exception) {
            if (! $this->batcher->failedEventWasDispatchedFor($notification)) {
                $exception = $this->dispatchFailedEvent($notifiable, $notification, $channel, $exception);
            }

            throw $exception;
        }

        // When the channel's mail was captured it hasn't been sent yet, so the sent or failed
        // event is dispatched once the batch has been sent. A channel that sent nothing, for
        // example because the notifiable has no address, is finished with straight away.
        if (! is_null($captured) && ($count = $this->mail->capturedCount($this->mailer)) > $captured) {
            $this->batched[] = [$notifiable, $notification, $channel, range($captured, $count - 1)];

            return;
        }

        $this->dispatchSentEvent($notifiable, $notification, $channel, $response);
    }

    /**
     * Get the channel's driver.
     *
     * @param  mixed  $channel
     * @return mixed
     */
    protected function driver($channel)
    {
        $driver = $this->manager->driver($channel);

        // The stock mail channel keeps the mail manager it was created with, which isn't the
        // fake if mail was faked after the channel was created. It's swapped for one that
        // uses the current mail manager, and that sends with the batch's mailer. A mail
        // channel registered by the application, or by another package, is used as it is.
        if (get_class($driver) === MailChannel::class && ($this->faked || ! is_null($this->attribute?->mailer))) {
            return $this->mailChannel ??= $this->manager->getContainer()
                ->make(BatchMailChannel::class)
                ->withDefaultMailer($this->attribute?->mailer);
        }

        return $driver;
    }

    /**
     * Take the notifications whose mail has been captured so far.
     *
     * @return array<int, array{0: mixed, 1: mixed, 2: mixed, 3: array<int, int>}>
     */
    protected function takeBatched(): array
    {
        [$batched, $this->batched] = [$this->batched, []];

        return $batched;
    }

    /**
     * Dispatch the sent and failed events for the notifications whose mail was batched.
     *
     * @param  array<int, array{0: mixed, 1: mixed, 2: mixed, 3: array<int, int>}>  $batched
     */
    protected function dispatchBatchedEvents(array $batched, BatchResult $result): ?Throwable
    {
        $sent = $result->sent();
        $failures = $result->failures();

        $listenerException = null;

        foreach ($batched as [$notifiable, $notification, $channel, $indexes]) {
            try {
                $this->withLocale($this->preferredLocale($notifiable, $notification), function () use ($notifiable, $notification, $channel, $indexes, $sent, $failures) {
                    foreach ($indexes as $index) {
                        if (isset($failures[$index])) {
                            $this->dispatchFailedEvent($notifiable, $notification, $channel, $failures[$index]);

                            return;
                        }
                    }

                    // A message the transport declined without an error has no sent message, so
                    // its notification is sent with a null response, as Laravel would send it.
                    $responses = array_map(fn ($index) => $sent[$index] ?? null, $indexes);

                    $this->dispatchSentEvent($notifiable, $notification, $channel, count($responses) === 1 ? $responses[0] : $responses);
                });
            } catch (Throwable $e) {
                $listenerException ??= $e;
            }
        }

        return $listenerException;
    }

    /**
     * Determine if the notification can be sent.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     * @param  mixed  $channel
     */
    protected function shouldSendNotification($notifiable, $notification, $channel): bool
    {
        if (method_exists($notification, 'shouldSend') &&
            $notification->shouldSend($notifiable, $channel) === false) {
            return false;
        }

        return $this->events->until(
            new NotificationSending($notifiable, $notification, $channel)
        ) !== false;
    }

    /**
     * Dispatch the sent event for the notification.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     * @param  mixed  $channel
     * @param  mixed  $response
     */
    protected function dispatchSentEvent($notifiable, $notification, $channel, $response): void
    {
        if (method_exists($notification, 'afterSending')) {
            $notification->afterSending($notifiable, $channel, $response);
        }

        $this->events->dispatch(
            new NotificationSent($notifiable, $notification, $channel, $response)
        );
    }

    /**
     * Dispatch the failed event for the notification.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     * @param  mixed  $channel
     */
    protected function dispatchFailedEvent($notifiable, $notification, $channel, Throwable $exception): Throwable
    {
        $exception = $this->normalizeException($exception);

        $this->events->dispatch(
            new NotificationFailed($notifiable, $notification, $channel, ['exception' => $exception])
        );

        return $exception;
    }

    /**
     * Convert an HTTP transport exception, which holds the HTTP response, as Laravel does.
     */
    protected function normalizeException(Throwable $exception): Throwable
    {
        return $exception instanceof HttpTransportException
            ? new TransportException($exception->getMessage(), $exception->getCode())
            : $exception;
    }

    /**
     * Get the notifiable's preferred locale for the notification.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     */
    protected function preferredLocale($notifiable, $notification): ?string
    {
        return $notification->locale ?? $this->locale ?? ($notifiable instanceof HasLocalePreference
            ? $notifiable->preferredLocale()
            : null);
    }
}
