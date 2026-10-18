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
use Illuminate\Support\Traits\Localizable;
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;
use NineteenNinetyFour\MailBatch\BatchResult;
use NineteenNinetyFour\MailBatch\Exceptions\BatchListenerException;
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
     * @var array<int, array{0: mixed, 1: mixed, 2: string, 3: array<int, int>}>
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
    public function send($notifiables, $notification, ?array $channels = null, array $ids = []): void
    {
        $notifiables = NotificationBatcher::formatNotifiables($notifiables);

        $original = clone $notification;

        $this->attribute = $this->batcher->attribute($original);

        $mailer = $this->batcher->mailManager()->mailer($this->attribute?->mailer);

        // When mail is faked, or a batch is already open on the mailer, the notification is sent
        // as Laravel would send it. Its mail is then recorded by the fake, or captured by the
        // batch that's already open, which dispatches the mail's own events when it's sent.
        if (! $mailer instanceof Mailer || $this->mail->capturing($mailer)) {
            $this->sendToNotifiables($notifiables, $original, $channels, $ids);

            return;
        }

        $batch = $this->batcher->configure(
            $this->mail->pending($mailer, $this->attribute?->mailer), $this->attribute,
        );

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

        $eventException = $this->dispatchBatchedEvents($result);

        if ($thrown = $exception ?? $listenerException ?? $eventException) {
            throw $thrown;
        }

        // When none of the messages could be sent, such as when the provider is unavailable,
        // the batch is failed as a whole so a queued job is retried. A partially sent batch
        // isn't, as retrying it would send the delivered messages a second time.
        if ($result->failed() && $result->sent() === []) {
            $result->throw();
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

            $notificationId = $ids[NotificationBatcher::notifiableIdKey($notifiables, $key, $notifiable)]
                ?? (string) Str::uuid();

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
     *
     * @throws Throwable
     */
    protected function sendToNotifiable($notifiable, string $id, $notification, string $channel): void
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

        $this->batcher->resetFailedEvent();

        try {
            $driver = $this->driver($channel);

            // Only the mail channel's own mail is matched to the notification. Mail that any
            // other channel sends through the batch's mailer is sent with the batch alone.
            $captured = $driver instanceof MailChannel && $this->mailer
                ? $this->mail->capturedCount($this->mailer)
                : null;

            $response = $driver->send($notifiable, $notification);
        } catch (Throwable $exception) {
            if (! $this->batcher->failedEventWasDispatched()) {
                $exception = $this->dispatchFailedEvent($notifiable, $notification, $channel, $exception);
            }

            $this->batcher->resetFailedEvent();

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
     * @return mixed
     */
    protected function driver(string $channel)
    {
        $driver = $this->manager->driver($channel);

        // The stock mail channel is swapped for one that sends with the batch's mailer. A mail
        // channel registered by the application, or by another package, is used as it is.
        if (! is_null($this->attribute?->mailer) && get_class($driver) === MailChannel::class) {
            return $this->mailChannel ??= $this->manager->getContainer()
                ->make(BatchMailChannel::class)
                ->withDefaultMailer($this->attribute->mailer);
        }

        return $driver;
    }

    /**
     * Dispatch the sent and failed events for the notifications whose mail was batched.
     */
    protected function dispatchBatchedEvents(BatchResult $result): ?Throwable
    {
        $sent = $result->sent();
        $failures = $result->failures();

        $listenerException = null;

        foreach ($this->batched as [$notifiable, $notification, $channel, $indexes]) {
            try {
                $this->withLocale($this->preferredLocale($notifiable, $notification), function () use ($notifiable, $notification, $channel, $indexes, $sent, $failures) {
                    foreach ($indexes as $index) {
                        if (isset($failures[$index])) {
                            $this->dispatchFailedEvent($notifiable, $notification, $channel, $failures[$index]);

                            return;
                        }
                    }

                    $responses = array_map(fn ($index) => $sent[$index] ?? null, $indexes);

                    $this->dispatchSentEvent($notifiable, $notification, $channel, count($responses) === 1 ? $responses[0] : $responses);
                });
            } catch (Throwable $e) {
                $listenerException ??= $e;
            }
        }

        $this->batched = [];

        return $listenerException;
    }

    /**
     * Determine if the notification can be sent.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     */
    protected function shouldSendNotification($notifiable, $notification, string $channel): bool
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
     * @param  mixed  $response
     */
    protected function dispatchSentEvent($notifiable, $notification, string $channel, $response): void
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
     */
    protected function dispatchFailedEvent($notifiable, $notification, string $channel, Throwable $exception): Throwable
    {
        if ($exception instanceof HttpTransportException) {
            $exception = new TransportException($exception->getMessage(), $exception->getCode());
        }

        $this->events->dispatch(
            new NotificationFailed($notifiable, $notification, $channel, ['exception' => $exception])
        );

        return $exception;
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
