<?php

namespace NineteenNinetyFour\MailBatch\Notifications;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ReadsClassAttributes;
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;
use NineteenNinetyFour\MailBatch\Capture\CapturingTransport;
use NineteenNinetyFour\MailBatch\Contracts\BatchTransport;

/**
 * Queues a notification for many notifiables, sending its mail in batches.
 *
 * Every channel other than mail is queued exactly as Laravel queues it: one job per
 * notifiable per channel. The mail channel's jobs are grouped into one job per chunk of
 * notifiables, but only for notifiables whose jobs would have been queued with the same
 * options, and each chunk is delayed so the mailer's rate limit holds across workers.
 */
class QueuedNotificationBatch
{
    use ReadsClassAttributes;

    /**
     * Create a new queued notification batch.
     */
    public function __construct(
        protected NotificationBatcher $batcher,
        protected ChannelManager $manager,
        protected Bus $bus,
        protected ?string $locale = null,
    ) {
        //
    }

    /**
     * Queue the notification for the notifiables.
     *
     * @param  mixed  $notifiables
     * @param  mixed  $notification
     */
    public function queue($notifiables, $notification): void
    {
        $notifiables = NotificationBatcher::formatNotifiables($notifiables);

        $original = clone $notification;

        $batchable = [];

        foreach ($notifiables as $notifiable) {
            $notificationId = (string) Str::uuid();

            foreach ((array) $original->via($notifiable) as $channel) {
                $notification = clone $original;

                if (! $notification->id) {
                    $notification->id = $notificationId;
                }

                if (! is_null($this->locale)) {
                    $notification->locale = $this->locale;
                }

                $options = $this->queueOptions($notifiable, $notification, $channel);

                // A deduplicator applies to a single notifiable's job, so those jobs aren't grouped.
                if ($this->isMailChannel($channel) && is_null($options['deduplicator'])) {
                    $batchable[] = [$notifiable, $notification, $channel, $options];

                    continue;
                }

                $this->dispatch($notifiable, $notification, $channel, $options);
            }
        }

        if ($batchable !== []) {
            $this->queueMailChunks($batchable, $original);
        }
    }

    /**
     * Resolve the options the notifiable's job is queued with, as Laravel resolves them.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     * @return array{connection: mixed, queue: mixed, delay: mixed, messageGroup: mixed, deduplicator: mixed, middleware: array}
     */
    protected function queueOptions($notifiable, $notification, string $channel): array
    {
        $connection = $this->getAttributeValue($notification, Connection::class, 'connection')
            ?? $this->manager->resolveConnectionFromQueueRoute($notification)
            ?? null;

        if (method_exists($notification, 'viaConnections')) {
            $connection = $notification->viaConnections()[$channel] ?? $connection;
        }

        $queue = $this->getAttributeValue($notification, QueueAttribute::class, 'queue')
            ?? $this->manager->resolveQueueFromQueueRoute($notification)
            ?? null;

        if (method_exists($notification, 'viaQueues')) {
            $queue = $notification->viaQueues()[$channel] ?? $queue;
        }

        $delay = method_exists($notification, 'withDelay')
            ? ($notification->withDelay($notifiable, $channel) ?? null)
            : $this->getAttributeValue($notification, Delay::class, 'delay');

        $messageGroup = $notification->messageGroup ?? (method_exists($notification, 'messageGroup') ? $notification->messageGroup() : null);

        if (method_exists($notification, 'withMessageGroups')) {
            $messageGroup = $notification->withMessageGroups($notifiable, $channel) ?? null;
        }

        $deduplicator = $notification->deduplicator ?? (method_exists($notification, 'deduplicationId') ? $notification->deduplicationId(...) : null);

        if (method_exists($notification, 'withDeduplicators')) {
            $deduplicator = $notification->withDeduplicators($notifiable, $channel) ?? null;
        }

        $middleware = $notification->middleware ?? [];

        if (method_exists($notification, 'middleware')) {
            $middleware = array_merge(
                $notification->middleware($notifiable, $channel),
                $middleware
            );
        }

        return [
            'connection' => $connection,
            'queue' => $queue,
            'delay' => is_array($delay) ? ($delay[$channel] ?? null) : $delay,
            'messageGroup' => is_array($messageGroup) ? ($messageGroup[$channel] ?? null) : $messageGroup,
            'deduplicator' => is_array($deduplicator) ? ($deduplicator[$channel] ?? null) : $deduplicator,
            'middleware' => $middleware,
        ];
    }

    /**
     * Queue the mail in jobs that each send it to a chunk of notifiables.
     *
     * @param  array<int, array{0: mixed, 1: mixed, 2: string, 3: array}>  $batchable
     * @param  mixed  $original
     */
    protected function queueMailChunks(array $batchable, $original): void
    {
        $groups = [];

        foreach ($batchable as [$notifiable, $notification, $channel, $options]) {
            foreach ($groups as $key => $group) {
                if ($group['channel'] === $channel && $this->sameQueueOption($group['options'], $options)) {
                    $groups[$key]['jobs'][] = [$notifiable, $notification];

                    continue 2;
                }
            }

            $groups[] = ['channel' => $channel, 'options' => $options, 'jobs' => [[$notifiable, $notification]]];
        }

        [$size, $interval, $requestsPerChunk] = $this->pacing($this->batcher->attribute($original));

        // Every chunk sends through the same mailer, so the chunks are paced against a single
        // schedule, whichever group they're in. Each one is offset from its group's own delay.
        $index = 0;

        foreach ($groups as ['channel' => $channel, 'options' => $options, 'jobs' => $jobs]) {
            foreach (array_chunk($jobs, $size) as $chunk) {
                $chunkOptions = array_merge($options, [
                    'delay' => $this->addMillisecondsToDelay($options['delay'], $index++ * $requestsPerChunk * $interval),
                ]);

                // A notifiable on its own is queued exactly as Laravel would queue it.
                if (count($chunk) === 1) {
                    $this->dispatch($chunk[0][0], $chunk[0][1], $channel, $chunkOptions);

                    continue;
                }

                $notifiables = $this->wrapNotifiables(array_column($chunk, 0));

                $notification = clone $original;

                if (! is_null($this->locale)) {
                    $notification->locale = $this->locale;
                }

                $ids = [];

                foreach ($notifiables as $key => $notifiable) {
                    $ids[NotificationBatcher::notifiableIdKey($notifiables, $key, $notifiable)] = $chunk[$key][1]->id;
                }

                $this->applyOptions(
                    new SendBatchedMailNotifications($notifiables, $notification, [$channel], $ids), $chunkOptions,
                );
            }
        }
    }

    /**
     * Get the chunk size, request interval, and requests per chunk.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function pacing(?BatchMail $attribute): array
    {
        $mailer = $this->batcher->mailManager()->mailer($attribute?->mailer);

        if (! $mailer instanceof Mailer) {
            return [$attribute?->size ?? 1, 0, 1];
        }

        $batch = $this->batcher->configure(
            $this->batcher->mail()->pending($mailer, $attribute?->mailer), $attribute,
        );

        $transport = $mailer->getSymfonyTransport();

        if ($transport instanceof CapturingTransport) {
            $transport = $transport->getTransport();
        }

        // A transport without a batch API sends each message in its own request, so a chunk
        // takes as many requests as it has notifiables. A transport with one sends a full
        // chunk in a single request, the chunk being as large as the transport accepts.
        if (! $transport instanceof BatchTransport) {
            $size = $batch->getSize() ?? 1;

            return [$size, $batch->getInterval(), $size];
        }

        $max = max(1, $transport->maxBatchSize());

        return [min($batch->getSize() ?? $max, $max), $batch->getInterval(), 1];
    }

    /**
     * Dispatch Laravel's own job for the notifiable.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     */
    protected function dispatch($notifiable, $notification, string $channel, array $options): void
    {
        $this->applyOptions(
            $this->manager->getContainer()->make(SendQueuedNotifications::class, [
                'notifiables' => $notifiable,
                'notification' => $notification,
                'channels' => [$channel],
            ]),
            $options,
        );
    }

    /**
     * Apply the queue options to the job and dispatch it.
     *
     * @param  mixed  $job
     */
    protected function applyOptions($job, array $options): void
    {
        $this->bus->dispatch(
            $job->onConnection($options['connection'])
                ->onQueue($options['queue'])
                ->delay($options['delay'])
                ->onGroup($options['messageGroup'])
                ->withDeduplicator($options['deduplicator'])
                ->through($options['middleware'])
        );
    }

    /**
     * Determine if two queue options are the same, so their jobs may be grouped together.
     */
    protected function sameQueueOption(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (! $this->sameQueueOption($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_object($a) && is_object($b) && ! $a instanceof Closure && ! $b instanceof Closure) {
            return get_class($a) === get_class($b) && $a == $b;
        }

        return $a === $b;
    }

    /**
     * Wrap a chunk of notifiables in a collection that can be serialized for the queue.
     */
    protected function wrapNotifiables(array $notifiables): Collection
    {
        $classes = array_unique(array_map(get_class(...), $notifiables));

        return count($classes) === 1 && $notifiables[0] instanceof Model
            ? new EloquentCollection($notifiables)
            : new Collection($notifiables);
    }

    /**
     * Add the given number of milliseconds to a queued job's delay.
     */
    protected function addMillisecondsToDelay(DateTimeInterface|DateInterval|int|null $delay, int $milliseconds): DateTimeInterface|DateInterval|int|null
    {
        if ($milliseconds === 0) {
            return $delay;
        }

        $availableAt = match (true) {
            $delay instanceof DateTimeInterface => Carbon::instance($delay),
            $delay instanceof DateInterval => Carbon::now()->add($delay),
            default => Carbon::now()->addSeconds((int) $delay),
        };

        return $availableAt->addMilliseconds($milliseconds);
    }

    /**
     * Determine if the channel is the mail channel.
     */
    protected function isMailChannel(string $channel): bool
    {
        return $channel === 'mail' || is_a($channel, MailChannel::class, true);
    }
}
