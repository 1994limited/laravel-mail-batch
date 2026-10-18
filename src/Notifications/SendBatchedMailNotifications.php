<?php

namespace NineteenNinetyFour\MailBatch\Notifications;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sends a notification's mail to a chunk of notifiables in a single batch.
 *
 * It's Laravel's queued notification job, so the notification's queue options such as its
 * tries, timeout, backoff and encryption apply to it the same way, but it handles a chunk.
 */
class SendBatchedMailNotifications extends SendQueuedNotifications
{
    /**
     * The notification ID of each notifiable, keyed like the notifiables.
     *
     * Every notifiable in the chunk shares the notification instance, so the ID each one
     * was given when it was queued is kept here. A notifiable's mail then keeps the same
     * ID as its other channels, such as its database notification.
     *
     * @var array<array-key, string>
     */
    public array $notificationIds = [];

    /**
     * The key the idempotency keys of the job's requests are derived from.
     *
     * It's serialized with the job, so a retried job sends each request with the same key as
     * the first attempt, and a provider that supports idempotency keys won't deliver twice.
     */
    public string $idempotencyKey;

    /**
     * Create a new job instance.
     *
     * @param  Collection  $notifiables
     * @param  Notification  $notification
     * @param  array<array-key, string>  $notificationIds
     */
    public function __construct($notifiables, $notification, array $channels, array $notificationIds = [])
    {
        parent::__construct($notifiables, $notification, $channels);

        $this->notificationIds = $notificationIds;

        $this->idempotencyKey = (string) Str::uuid();
    }

    /**
     * Send the notifications.
     */
    public function handle(ChannelManager $manager): void
    {
        $manager->getContainer()->make(NotificationBatcher::class)->sendNow(
            $manager, $this->notifiables, $this->notification, $this->channels, $this->notificationIds,
            $this->notification->locale ?? null, $this->idempotencyKey,
        );
    }
}
