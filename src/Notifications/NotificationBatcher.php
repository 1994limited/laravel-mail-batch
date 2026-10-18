<?php

namespace NineteenNinetyFour\MailBatch\Notifications;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Traits\ReadsClassAttributes;
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;
use NineteenNinetyFour\MailBatch\MailBatcher;
use NineteenNinetyFour\MailBatch\PendingBatch;

/**
 * Sends notifications to many notifiables with their mail sent in batches.
 */
class NotificationBatcher
{
    use ReadsClassAttributes;

    /**
     * Indicates whether a NotificationFailed event has been dispatched.
     */
    protected bool $failedEventWasDispatched = false;

    /**
     * Create a new notification batcher instance.
     */
    public function __construct(
        protected Container $container,
        protected Dispatcher $events,
        protected Bus $bus,
        protected MailBatcher $mail,
    ) {
        $this->events->listen(NotificationFailed::class, fn () => $this->failedEventWasDispatched = true);
    }

    /**
     * Send the notification to the notifiables, queueing it if it should be queued.
     *
     * @param  mixed  $notifiables
     * @param  mixed  $notification
     */
    public function send(ChannelManager $manager, $notifiables, $notification, ?string $locale = null): void
    {
        if ($notification instanceof ShouldQueue) {
            (new QueuedNotificationBatch($this, $manager, $this->bus, $locale))->queue($notifiables, $notification);

            return;
        }

        $this->sendNow($manager, $notifiables, $notification, null, [], $locale);
    }

    /**
     * Send the notification to the notifiables immediately.
     *
     * @param  mixed  $notifiables
     * @param  mixed  $notification
     * @param  array<array-key, string>  $ids
     */
    public function sendNow(ChannelManager $manager, $notifiables, $notification, ?array $channels = null, array $ids = [], ?string $locale = null): void
    {
        (new SyncNotificationBatch($this, $manager, $this->events, $this->mail, $locale))
            ->send($notifiables, $notification, $channels, $ids);
    }

    /**
     * Get the notification's batch configuration.
     *
     * @param  mixed  $notification
     */
    public function attribute($notification): ?BatchMail
    {
        return $this->getAttributeInstance($notification, BatchMail::class);
    }

    /**
     * Apply the notification's batch configuration to the pending batch.
     */
    public function configure(PendingBatch $batch, ?BatchMail $attribute): PendingBatch
    {
        if (! is_null($attribute?->size)) {
            $batch->size($attribute->size);
        }

        if (! is_null($attribute?->interval)) {
            $batch->interval($attribute->interval);
        }

        if (! is_null($attribute?->perSecond)) {
            $batch->perSecond($attribute->perSecond);
        }

        return $batch;
    }

    /**
     * Get the mail manager, or the mail fake.
     *
     * @return MailManager|MailFake
     */
    public function mailManager()
    {
        return $this->container->make('mail.manager');
    }

    /**
     * Get the mail batcher.
     */
    public function mail(): MailBatcher
    {
        return $this->mail;
    }

    /**
     * Forget that a NotificationFailed event was dispatched.
     *
     * @internal
     */
    public function resetFailedEvent(): void
    {
        $this->failedEventWasDispatched = false;
    }

    /**
     * Determine if a NotificationFailed event was dispatched since the flag was last reset.
     *
     * @internal
     */
    public function failedEventWasDispatched(): bool
    {
        return $this->failedEventWasDispatched;
    }

    /**
     * Format the notifiables into a Collection / array if necessary.
     *
     * @param  mixed  $notifiables
     * @return Collection|array
     */
    public static function formatNotifiables($notifiables)
    {
        if (! $notifiables instanceof Collection && ! is_array($notifiables)) {
            return $notifiables instanceof Model
                ? new EloquentCollection([$notifiables])
                : [$notifiables];
        }

        return $notifiables;
    }

    /**
     * Get the key a notifiable's notification ID is stored under for a queued chunk.
     *
     * A collection of models is restored from the database when the job is processed and leaves
     * out models deleted in the meantime, so models are keyed by their primary key instead of
     * their position. Any other collection is serialized as is, so its keys are kept as is.
     *
     * @param  mixed  $notifiables
     * @param  array-key  $key
     * @param  mixed  $notifiable
     * @return array-key
     */
    public static function notifiableIdKey($notifiables, $key, $notifiable)
    {
        return $notifiables instanceof EloquentCollection && $notifiable instanceof Model
            ? $notifiable->getQueueableId()
            : $key;
    }
}
