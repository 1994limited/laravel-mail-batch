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
use WeakMap;

/**
 * Sends notifications to many notifiables with their mail sent in batches.
 */
class NotificationBatcher
{
    use ReadsClassAttributes;

    /**
     * The notifications a NotificationFailed event has been dispatched for.
     *
     * Laravel's notification sender keeps a single flag, so that a channel which dispatches the
     * event itself doesn't get a second one. Keying it by the notification instance being
     * sent keeps concurrent and nested sends from resetting or setting each other's flag.
     *
     * @var WeakMap<object, true>
     */
    protected WeakMap $failed;

    /**
     * Create a new notification batcher instance.
     */
    public function __construct(
        protected Container $container,
        protected Dispatcher $events,
        protected Bus $bus,
        protected MailBatcher $mail,
    ) {
        $this->failed = new WeakMap;

        $this->events->listen(NotificationFailed::class, function (NotificationFailed $event) {
            if (is_object($event->notification)) {
                $this->failed[$event->notification] = true;
            }
        });
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
    public function sendNow(ChannelManager $manager, $notifiables, $notification, ?array $channels = null, array $ids = [], ?string $locale = null, ?string $idempotencyKey = null): void
    {
        (new SyncNotificationBatch($this, $manager, $this->events, $this->mail, $locale))
            ->send($notifiables, $notification, $channels, $ids, $idempotencyKey);
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
     * Determine if a NotificationFailed event was dispatched for the given notification.
     *
     * @internal
     */
    public function failedEventWasDispatchedFor(object $notification): bool
    {
        return isset($this->failed[$notification]);
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
}
