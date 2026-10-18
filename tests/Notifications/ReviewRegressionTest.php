<?php

namespace NineteenNinetyFour\MailBatch\Tests\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use LogicException;
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;
use NineteenNinetyFour\MailBatch\Exceptions\BatchSendException;
use NineteenNinetyFour\MailBatch\Facades\MailBatch;
use NineteenNinetyFour\MailBatch\Notifications\SendBatchedMailNotifications;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\NotifiableUser;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\SequencedBusFake;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\TestMailable;
use NineteenNinetyFour\MailBatch\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;

class ReviewRegressionTest extends TestCase
{
    protected SequencedBusFake $bus;

    protected function notifiables(string ...$names): array
    {
        return array_map(
            fn ($name) => (new AnonymousNotifiable)->route('mail', "{$name}@laravel.com"),
            $names ?: ['taylor', 'dries', 'james'],
        );
    }

    protected function fakeBus(): void
    {
        Bus::swap($this->bus = new SequencedBusFake(Bus::getFacadeRoot()));
    }

    public function test_mail_faked_after_the_mail_channel_was_created_never_reaches_the_transport(): void
    {
        $this->app->make(ChannelManager::class)->driver('mail');

        Mail::fake();

        NotificationFacade::batch($this->notifiables(), new RegressionNotification);

        $this->assertSame([], $this->transport->batches);
        $this->assertSame([], $this->transport->singles);
    }

    public function test_messages_declined_by_the_transport_are_sent_notifications_with_a_null_response(): void
    {
        $this->transport->max = 1;
        $this->transport->declined = ['dries@laravel.com'];

        $responses = [];

        Event::listen(NotificationSent::class, function ($event) use (&$responses) {
            $responses[$event->notifiable->routeNotificationFor('mail')] = $event->response?->getMessageId();
        });

        NotificationFacade::batch($this->notifiables('taylor', 'dries'), new RegressionNotification);

        $this->assertSame(['taylor@laravel.com' => 'id-taylor@laravel.com', 'dries@laravel.com' => null], $responses);
    }

    public function test_a_batch_that_is_only_declined_does_not_throw(): void
    {
        $this->transport->declined = ['taylor@laravel.com'];

        NotificationFacade::batch($this->notifiables('taylor'), new RegressionNotification);

        $this->assertSame([], $this->transport->singles);
    }

    public function test_http_transport_exceptions_are_converted_when_the_whole_batch_fails(): void
    {
        $this->transport->onBatch = function () {
            throw new HttpTransportException('Server error.', new MockResponse('', ['http_code' => 500]));
        };

        $failures = [];

        Event::listen(NotificationFailed::class, function ($event) use (&$failures) {
            $failures[] = get_class($event->data['exception']);
        });

        try {
            NotificationFacade::batch($this->notifiables('taylor', 'dries'), new RegressionNotification);

            $this->fail('The batch was not failed.');
        } catch (BatchSendException $e) {
            $this->assertSame(TransportException::class, get_class($e->getPrevious()));
        }

        $this->assertSame([TransportException::class, TransportException::class], $failures);
    }

    public function test_channel_enums_are_supported(): void
    {
        NotificationFacade::batch($this->notifiables(), new EnumChannelNotification);

        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com']], $this->transport->batches);
    }

    public function test_mail_queued_before_an_exception_is_still_queued(): void
    {
        $this->fakeBus();

        try {
            NotificationFacade::batch($this->notifiables('taylor', 'dries', 'fail'), new QueuedRegressionNotification);

            $this->fail('The exception was not rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('No channels.', $e->getMessage());
        }

        $jobs = $this->bus->sequence()->filter(fn ($job) => $job instanceof SendBatchedMailNotifications);

        $this->assertCount(1, $jobs);
        $this->assertCount(2, $jobs->first()->notifiables);
    }

    public function test_notifiables_whose_notification_differs_are_not_grouped(): void
    {
        $this->fakeBus();

        NotificationFacade::batch($this->notifiables('taylor', 'dries'), new StatefulQueuedNotification);

        $jobs = $this->bus->sequence();

        $this->assertCount(2, $jobs);
        $this->assertTrue($jobs->every(fn ($job) => get_class($job) === SendQueuedNotifications::class));
        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com'], $jobs->map(fn ($job) => $job->notification->lastRecipient)->all());
    }

    public function test_models_are_only_grouped_with_models_they_can_be_serialized_with(): void
    {
        $this->fakeBus();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
        });

        $taylor = NotifiableUser::forceCreate(['email' => 'taylor@laravel.com']);
        $dries = NotifiableUser::forceCreate(['email' => 'dries@laravel.com']);

        NotificationFacade::batch(
            [$taylor, ...$this->notifiables('james', 'nuno'), $dries, $taylor],
            new QueuedRegressionNotification,
        );

        $jobs = $this->bus->sequence()->values();

        $this->assertCount(2, $jobs);
        $this->assertSame([NotifiableUser::class], array_unique($jobs[0]->notifiables->map(fn ($n) => get_class($n))->all()));
        $this->assertCount(3, $jobs[0]->notifiables);
        $this->assertCount(2, $jobs[1]->notifiables);

        // The same model twice keeps the two IDs it was queued with.
        $this->assertCount(3, array_unique($jobs[0]->notificationIds));

        $restored = unserialize(serialize($jobs[0]));

        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com', 'taylor@laravel.com'], $restored->notifiables->pluck('email')->all());
    }

    public function test_a_retried_job_sends_its_requests_with_the_same_idempotency_keys(): void
    {
        $this->fakeBus();

        NotificationFacade::batch($this->notifiables('taylor', 'dries'), new QueuedRegressionNotification);

        $payload = serialize($this->bus->sequence()->first());

        $this->transport->failRequests = 1;

        try {
            unserialize($payload)->handle($this->app->make(ChannelManager::class));
        } catch (BatchSendException) {
            //
        }

        unserialize($payload)->handle($this->app->make(ChannelManager::class));

        $keys = array_column($this->transport->options, 'idempotency_key');

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_a_faked_batch_whose_callback_throws_records_nothing(): void
    {
        Mail::fake();

        try {
            Mail::batch()->capture(function () {
                Mail::send(new TestMailable);

                throw new RuntimeException('Oops.');
            });
        } catch (RuntimeException) {
            //
        }

        Mail::assertNothingSent();
        MailBatch::assertNotBatched();
    }

    public function test_faked_batches_cannot_be_nested(): void
    {
        Mail::fake();

        $this->expectException(LogicException::class);

        Mail::batch()->capture(fn () => Mail::batch()->capture(fn () => null));
    }
}

#[BatchMail]
class RegressionNotification extends Notification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Hello')->line('Hello!');
    }
}

enum NotificationChannel: string
{
    case Mail = 'mail';
}

class EnumChannelNotification extends RegressionNotification
{
    public function via($notifiable)
    {
        return [NotificationChannel::Mail];
    }
}

class QueuedRegressionNotification extends RegressionNotification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        if ($notifiable->routeNotificationFor('mail') === 'fail@laravel.com') {
            throw new RuntimeException('No channels.');
        }

        return ['mail'];
    }
}

class StatefulQueuedNotification extends QueuedRegressionNotification
{
    public ?string $lastRecipient = null;

    public function via($notifiable)
    {
        $this->lastRecipient = $notifiable->routeNotificationFor('mail');

        return ['mail'];
    }
}
