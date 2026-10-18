<?php

namespace NineteenNinetyFour\MailBatch\Tests\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage as IlluminateSentMessage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;
use NineteenNinetyFour\MailBatch\Exceptions\BatchSendException;
use NineteenNinetyFour\MailBatch\Notifications\SendBatchedMailNotifications;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\NotifiableUser;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\SequencedBusFake;
use NineteenNinetyFour\MailBatch\Tests\TestCase;
use RuntimeException;

class SendingBatchedNotificationsTest extends TestCase
{
    protected SequencedBusFake $bus;

    protected function fakeBus(): void
    {
        Bus::swap($this->bus = new SequencedBusFake(Bus::getFacadeRoot()));
    }

    protected function jobs(): Collection
    {
        return $this->bus->sequence();
    }

    protected function setUp(): void
    {
        parent::setUp();

        BatchedMailNotification::$afterSending = [];
    }

    protected function notifiables(string ...$names): array
    {
        return array_map(
            fn ($name) => (new AnonymousNotifiable)->route('mail', "{$name}@laravel.com"),
            $names ?: ['taylor', 'dries', 'james'],
        );
    }

    protected function recipientOf(NotificationSent|NotificationFailed $event): string
    {
        return $event->notifiable->routeNotificationFor('mail');
    }

    public function test_mail_of_a_batched_notification_is_sent_in_one_request()
    {
        NotificationFacade::batch($this->notifiables(), new BatchedMailNotification);

        $this->assertSame([
            ['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com'],
        ], $this->transport->batches);
        $this->assertSame([], $this->transport->singles);
    }

    public function test_sent_events_are_dispatched_after_the_batch_is_sent_with_the_provider_response()
    {
        $events = [];

        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$events) {
            $events[] = $event;
        });

        $this->transport->onBatch = function () use (&$events) {
            $this->assertSame([], $events);
        };

        NotificationFacade::batch($this->notifiables(), $notification = new BatchedMailNotification);

        $this->assertCount(3, $events);

        foreach ($events as $event) {
            $this->assertSame('mail', $event->channel);
            $this->assertInstanceOf(IlluminateSentMessage::class, $event->response);
            $this->assertSame(
                'id-'.$this->recipientOf($event),
                $event->response->getMessageId(),
            );
        }

        $this->assertSame(
            ['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com'],
            array_map(fn ($event) => $this->recipientOf($event), $events),
        );
    }

    public function test_after_sending_receives_the_sent_message()
    {
        NotificationFacade::batch($this->notifiables('taylor', 'dries'), $notification = new BatchedMailNotification);

        $this->assertSame(['id-taylor@laravel.com', 'id-dries@laravel.com'], BatchedMailNotification::$afterSending);
    }

    public function test_failed_messages_dispatch_notification_failed_for_their_notifiable()
    {
        $this->transport->rejected = ['dries@laravel.com'];

        $sent = $failed = [];

        Event::listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[] = $this->recipientOf($event);
        });

        Event::listen(NotificationFailed::class, function ($event) use (&$failed) {
            $failed[$this->recipientOf($event)] = $event->data['exception'];
        });

        NotificationFacade::batch($this->notifiables(), new BatchedMailNotification);

        $this->assertSame(['taylor@laravel.com', 'james@laravel.com'], $sent);
        $this->assertSame(['dries@laravel.com'], array_keys($failed));
        $this->assertSame('Rejected dries@laravel.com.', $failed['dries@laravel.com']->getMessage());
    }

    public function test_a_failed_request_dispatches_notification_failed_for_every_notifiable_in_it()
    {
        $this->transport->failRequests = 1;

        $sent = $failed = [];

        Event::listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[] = $this->recipientOf($event);
        });

        Event::listen(NotificationFailed::class, function ($event) use (&$failed) {
            $failed[] = $this->recipientOf($event);
        });

        try {
            NotificationFacade::batch($this->notifiables(), new BatchedMailNotification);

            $this->fail('The batch was not failed.');
        } catch (BatchSendException $e) {
            $this->assertSame('3 of 3 messages in the batch failed to send.', $e->getMessage());
        }

        $this->assertSame([], $sent);
        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com'], $failed);
    }

    public function test_notifications_returning_mailables_are_mapped_to_their_notifiable()
    {
        $this->transport->rejected = ['taylor@laravel.com'];

        $sent = $failed = [];

        Event::listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[$this->recipientOf($event)] = $event->response->getMessageId();
        });

        Event::listen(NotificationFailed::class, function ($event) use (&$failed) {
            $failed[] = $this->recipientOf($event);
        });

        NotificationFacade::batch($this->notifiables(), new BatchedMailableNotification);

        $this->assertCount(1, $this->transport->batches);
        $this->assertSame(['taylor@laravel.com'], $failed);
        $this->assertSame([
            'dries@laravel.com' => 'id-dries@laravel.com',
            'james@laravel.com' => 'id-james@laravel.com',
        ], $sent);
    }

    public function test_notifiables_sharing_a_preset_id_are_still_mapped_by_capture_order()
    {
        $this->transport->rejected = ['james@laravel.com'];

        $failed = [];

        Event::listen(NotificationFailed::class, function ($event) use (&$failed) {
            $failed[] = $this->recipientOf($event);
        });

        $notification = new BatchedMailNotification;
        $notification->id = 'shared-id';

        NotificationFacade::batch($this->notifiables(), $notification);

        $this->assertSame(['james@laravel.com'], $failed);
    }

    public function test_the_attribute_configures_the_batch()
    {
        NotificationFacade::batch($this->notifiables(), new SmallBatchedMailNotification);

        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com']], $this->transport->batches);
        $this->assertSame(['james@laravel.com'], $this->transport->singles);
    }

    public function test_mail_cancelled_by_sending_listeners_is_not_part_of_the_batch()
    {
        Event::listen(MessageSending::class, function (MessageSending $event) {
            return $event->message->getTo()[0]->getAddress() !== 'dries@laravel.com';
        });

        $responses = [];

        Event::listen(NotificationSent::class, function ($event) use (&$responses) {
            $responses[$this->recipientOf($event)] = $event->response?->getMessageId();
        });

        NotificationFacade::batch($this->notifiables(), new BatchedMailNotification);

        $this->assertSame([['taylor@laravel.com', 'james@laravel.com']], $this->transport->batches);
        $this->assertSame([
            'dries@laravel.com' => null,
            'taylor@laravel.com' => 'id-taylor@laravel.com',
            'james@laravel.com' => 'id-james@laravel.com',
        ], $responses);
    }

    public function test_an_exception_still_sends_the_mail_captured_before_it()
    {
        $sent = $failed = [];

        Event::listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[] = $this->recipientOf($event);
        });

        Event::listen(NotificationFailed::class, function ($event) use (&$failed) {
            $failed[] = $this->recipientOf($event);
        });

        try {
            NotificationFacade::batch(
                $this->notifiables('taylor', 'fail', 'james'),
                new BatchedMailNotification,
            );

            $this->fail('The exception was not rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not build the message.', $e->getMessage());
        }

        $this->assertSame(['taylor@laravel.com'], $this->transport->singles);
        $this->assertSame(['taylor@laravel.com'], $sent);
        $this->assertSame(['fail@laravel.com'], $failed);
    }

    public function test_batched_notifications_inside_an_open_batch_are_captured_by_that_batch()
    {
        $responses = [];

        Event::listen(NotificationSent::class, function ($event) use (&$responses) {
            $responses[] = $event->response;
        });

        $result = Mail::batch()->size(2)->capture(function () {
            NotificationFacade::batch($this->notifiables(), new BatchedMailNotification);
        });

        $this->assertCount(3, $result->sent());
        $this->assertSame([null, null, null], $responses);
        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com']], $this->transport->batches);
        $this->assertSame(['james@laravel.com'], $this->transport->singles);
    }

    public function test_notifications_without_the_attribute_are_sent_one_at_a_time()
    {
        $responses = [];

        Event::listen(NotificationSent::class, function ($event) use (&$responses) {
            $responses[] = $event->response->getMessageId();
        });

        NotificationFacade::send($this->notifiables(), new UnbatchedMailNotification);

        $this->assertSame([], $this->transport->batches);
        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com'], $this->transport->singles);
        $this->assertSame(['id-taylor@laravel.com', 'id-dries@laravel.com', 'id-james@laravel.com'], $responses);
    }

    public function test_batched_notifications_are_sent_with_the_mailer_from_the_attribute()
    {
        NotificationFacade::batch($this->notifiables(), new ArrayMailerBatchedNotification);

        $this->assertSame([], $this->transport->batches);
        $this->assertCount(3, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_queued_batched_notifications_are_queued_in_chunks()
    {
        $this->fakeBus();

        Carbon::setTestNow('2026-10-18 12:00:00');

        NotificationFacade::batch(
            $this->notifiables('taylor', 'dries', 'james', 'nuno', 'jess'),
            new QueuedBatchedNotification,
        );

        $jobs = $this->jobs();

        $databaseJobs = $jobs->filter(fn ($job) => $job->channels === ['database']);
        $mailJobs = $jobs->filter(fn ($job) => $job->channels === ['mail'])->values();

        $this->assertCount(5, $databaseJobs);
        $this->assertTrue($databaseJobs->every(fn ($job) => $job->notifiables->count() === 1));

        $this->assertSame([
            ['taylor@laravel.com', 'dries@laravel.com'],
            ['james@laravel.com', 'nuno@laravel.com'],
            ['jess@laravel.com'],
        ], $mailJobs->map(fn ($job) => $job->notifiables->map(fn ($notifiable) => $notifiable->routeNotificationFor('mail'))->all())->all());

        $this->assertNull($mailJobs[0]->delay);
        $this->assertEquals(Carbon::parse('2026-10-18 12:00:01'), $mailJobs[1]->delay);
        $this->assertEquals(Carbon::parse('2026-10-18 12:00:02'), $mailJobs[2]->delay);

        $this->assertNull($mailJobs[0]->notification->id);
        $this->assertNotNull($mailJobs[2]->notification->id);

        // Each notifiable in a chunk keeps the ID its other channels were queued with.
        $databaseIds = $databaseJobs->mapWithKeys(fn ($job) => [
            $job->notifiables->first()->routeNotificationFor('mail') => $job->notification->id,
        ]);

        $this->assertSame([
            0 => $databaseIds['taylor@laravel.com'],
            1 => $databaseIds['dries@laravel.com'],
        ], $mailJobs[0]->notificationIds);
        $this->assertInstanceOf(SendBatchedMailNotifications::class, $mailJobs[0]);
        $this->assertSame(SendQueuedNotifications::class, get_class($mailJobs[2]));
        $this->assertSame($databaseIds['jess@laravel.com'], $mailJobs[2]->notification->id);
    }

    public function test_queued_chunks_are_sent_in_a_batch_when_handled()
    {
        $this->fakeBus();

        NotificationFacade::batch($this->notifiables(), new QueuedBatchedNotification);

        $events = [];

        Event::listen(NotificationSent::class, function ($event) use (&$events) {
            $events[$this->recipientOf($event)] = $event->notification->id;
        });

        $jobs = $this->jobs();

        $databaseIds = $jobs->filter(fn ($job) => $job->channels === ['database'])->mapWithKeys(fn ($job) => [
            $job->notifiables->first()->routeNotificationFor('mail') => $job->notification->id,
        ]);

        $job = unserialize(serialize($jobs->first(fn ($job) => $job->channels === ['mail'])));

        $job->handle($this->app->make(ChannelManager::class));

        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com']], $this->transport->batches);
        $this->assertSame([
            'taylor@laravel.com' => $databaseIds['taylor@laravel.com'],
            'dries@laravel.com' => $databaseIds['dries@laravel.com'],
        ], $events);
    }

    public function test_queued_chunks_of_models_keep_each_models_id_when_a_model_is_missing()
    {
        $this->fakeBus();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
        });

        $users = collect(['taylor', 'dries', 'james'])->map(
            fn ($name) => NotifiableUser::forceCreate(['email' => "{$name}@laravel.com"])
        );

        NotificationFacade::batch(NotifiableUser::all(), new QueuedBatchedNotification);

        $jobs = $this->jobs();

        $databaseIds = $jobs->filter(fn ($job) => $job->channels === ['database'])->mapWithKeys(fn ($job) => [
            $job->notifiables->first()->email => $job->notification->id,
        ]);

        $payload = serialize($jobs->first(fn ($job) => $job->channels === ['mail']));

        // A model deleted before the job is processed is left out of the restored collection.
        // The models that remain must still be sent with the IDs they were queued with.
        $users[0]->delete();

        $events = [];

        Event::listen(NotificationSent::class, function ($event) use (&$events) {
            $events[$event->notifiable->email] = $event->notification->id;
        });

        unserialize($payload)->handle($this->app->make(ChannelManager::class));

        $this->assertSame(['dries@laravel.com' => $databaseIds['dries@laravel.com']], $events);
    }

    public function test_queued_chunks_are_delayed_from_the_notifiables_delay()
    {
        $this->fakeBus();

        Carbon::setTestNow('2026-10-18 12:00:00');

        NotificationFacade::batch(
            $this->notifiables('taylor', 'dries', 'james'),
            new QueuedBatchedNotification(delayFor: 60),
        );

        $mailJobs = $this->jobs()
            ->filter(fn ($job) => $job->channels === ['mail'])->values();

        $this->assertSame(60, $mailJobs[0]->delay);
        $this->assertEquals(Carbon::parse('2026-10-18 12:01:01'), $mailJobs[1]->delay);
    }

    public function test_notifiables_with_different_queue_options_are_not_grouped_together()
    {
        $this->fakeBus();

        Carbon::setTestNow('2026-10-18 12:00:00');

        NotificationFacade::batch(
            $this->notifiables('taylor', 'dries', 'james'),
            new QueuedBatchedNotification(delays: [
                'taylor@laravel.com' => 10,
                'dries@laravel.com' => 20,
                'james@laravel.com' => 10,
            ]),
        );

        $mailJobs = $this->jobs()
            ->filter(fn ($job) => $job->channels === ['mail'])->values();

        $this->assertCount(2, $mailJobs);

        $this->assertSame(10, $mailJobs[0]->delay);
        $this->assertSame(
            ['taylor@laravel.com', 'james@laravel.com'],
            $mailJobs[0]->notifiables->map->routeNotificationFor('mail')->all(),
        );

        // A notifiable that has no others to be grouped with is queued as without a batch, but it's
        // still paced against the chunks before it, as they're all sent with the same mailer.
        $this->assertEquals(Carbon::parse('2026-10-18 12:00:21'), $mailJobs[1]->delay);
        $this->assertSame(['dries@laravel.com'], $mailJobs[1]->notifiables->map->routeNotificationFor('mail')->all());
        $this->assertNotNull($mailJobs[1]->notification->id);
    }

    public function test_notifiables_with_a_deduplicator_are_not_grouped()
    {
        $this->fakeBus();

        NotificationFacade::batch($this->notifiables(), new DeduplicatedQueuedBatchedNotification);

        $mailJobs = $this->jobs()
            ->filter(fn ($job) => $job->channels === ['mail']);

        $this->assertCount(3, $mailJobs);
        $this->assertTrue($mailJobs->every(fn ($job) => $job->notifiables->count() === 1));
    }

    public function test_mailables_returned_by_batched_notifications_are_sent_with_the_mailer_from_the_attribute()
    {
        NotificationFacade::batch($this->notifiables(), new ArrayMailerBatchedMailableNotification);

        $this->assertSame([], $this->transport->batches);
        $this->assertSame([], $this->transport->singles);
        $this->assertCount(3, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_a_notification_batched_on_another_mailer_during_a_batch_keeps_the_outer_batch_intact()
    {
        $sent = [];

        Event::listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[] = get_class($event->notification).':'.$this->recipientOf($event);
        });

        $nested = false;

        Event::listen(NotificationSending::class, function ($event) use (&$nested) {
            if (! $nested && $event->notifiable->routeNotificationFor('mail') === 'dries@laravel.com') {
                $nested = true;

                NotificationFacade::batch($this->notifiables('nuno'), new ArrayMailerBatchedNotification);
            }
        });

        $this->transport->rejected = ['dries@laravel.com'];

        $failed = [];

        Event::listen(NotificationFailed::class, function ($event) use (&$failed) {
            $failed[] = $this->recipientOf($event);
        });

        NotificationFacade::batch($this->notifiables(), new SmallBatchedMailNotification);

        $this->assertSame(['dries@laravel.com'], $failed);
        $this->assertSame([
            ArrayMailerBatchedNotification::class.':nuno@laravel.com',
            SmallBatchedMailNotification::class.':taylor@laravel.com',
            SmallBatchedMailNotification::class.':james@laravel.com',
        ], $sent);
    }

    public function test_mail_sent_by_other_channels_is_not_matched_to_their_notification()
    {
        $responses = [];

        Event::listen(NotificationSent::class, function ($event) use (&$responses) {
            $responses[$event->channel] = $event->response;
        });

        NotificationFacade::batch($this->notifiables('taylor'), new BatchedCustomChannelNotification);

        $this->assertSame([BatchedCustomChannel::class => 'custom-response'], $responses);
        $this->assertSame(['ops@laravel.com'], $this->transport->singles);
    }

    public function test_queued_notifications_without_the_attribute_are_queued_per_notifiable()
    {
        $this->fakeBus();

        NotificationFacade::send($this->notifiables(), new UnbatchedQueuedNotification);

        $jobs = $this->jobs();

        $this->assertCount(3, $jobs);
        $this->assertTrue($jobs->every(fn ($job) => $job->notifiables->count() === 1 && $job->delay === null));
        $this->assertTrue($jobs->every(fn ($job) => $job->notification->id !== null));
    }

    public function test_queued_chunks_use_one_notifiable_per_request_for_transports_without_a_batch_api()
    {
        $this->fakeBus();

        Carbon::setTestNow('2026-10-18 12:00:00');

        NotificationFacade::batch($this->notifiables(), new ArrayMailerQueuedBatchedNotification);

        $mailJobs = $this->jobs()->values();

        // The array transport has no batch API: chunks of 2 take 2 requests, 500ms apart.
        $this->assertCount(2, $mailJobs);
        $this->assertCount(2, $mailJobs[0]->notifiables);
        $this->assertNull($mailJobs[0]->delay);
        $this->assertEquals(Carbon::parse('2026-10-18 12:00:01'), $mailJobs[1]->delay);
    }
}

#[BatchMail]
class BatchedMailNotification extends Notification
{
    public static array $afterSending = [];

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        if ($notifiable->routeNotificationFor('mail') === 'fail@laravel.com') {
            throw new RuntimeException('Could not build the message.');
        }

        return (new MailMessage)->subject('Weekly summary')->line('Hello!');
    }

    public function afterSending($notifiable, $channel, $response)
    {
        static::$afterSending[] = $response?->getMessageId();
    }
}

#[BatchMail(size: 2)]
class SmallBatchedMailNotification extends BatchedMailNotification
{
    //
}

#[BatchMail(mailer: 'array')]
class ArrayMailerBatchedNotification extends BatchedMailNotification
{
    //
}

class UnbatchedMailNotification extends Notification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Weekly summary')->line('Hello!');
    }
}

#[BatchMail]
class BatchedMailableNotification extends Notification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new BatchedNotificationMailable)->to($notifiable->routeNotificationFor('mail'));
    }
}

#[BatchMail(mailer: 'array')]
class ArrayMailerBatchedMailableNotification extends BatchedMailableNotification
{
    //
}

#[BatchMail]
class BatchedCustomChannelNotification extends Notification
{
    public function via($notifiable)
    {
        return [BatchedCustomChannel::class];
    }
}

class BatchedCustomChannel
{
    public function send($notifiable, $notification)
    {
        Mail::raw('Sent a notification.', fn ($message) => $message->to('ops@laravel.com'));

        return 'custom-response';
    }
}

class BatchedNotificationMailable extends Mailable
{
    public function build()
    {
        return $this->subject('Hello')->html('<p>Hello</p>');
    }
}

#[BatchMail(size: 2, interval: 1000)]
class QueuedBatchedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?int $delayFor = null, public array $delays = [])
    {
        //
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function withDelay($notifiable, $channel)
    {
        return $this->delays[$notifiable->routeNotificationFor('mail')] ?? $this->delayFor;
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Weekly summary')->line('Hello!');
    }

    public function toArray($notifiable)
    {
        return [];
    }
}

#[BatchMail]
class DeduplicatedQueuedBatchedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function deduplicationId($payload, $queue)
    {
        return 'id';
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Weekly summary')->line('Hello!');
    }
}

class UnbatchedQueuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Weekly summary')->line('Hello!');
    }
}

#[BatchMail(size: 2, perSecond: 2, mailer: 'array')]
class ArrayMailerQueuedBatchedNotification extends UnbatchedQueuedNotification
{
    //
}
