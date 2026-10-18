<?php

namespace NineteenNinetyFour\MailBatch\Tests\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Events\NotificationSkipped;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\NotifiableUser;
use NineteenNinetyFour\MailBatch\Tests\TestCase;

class NotificationParityTest extends TestCase
{
    protected function notifiables(string ...$names): array
    {
        return array_map(
            fn ($name) => (new AnonymousNotifiable)->route('mail', "{$name}@laravel.com"),
            $names ?: ['taylor', 'dries', 'james'],
        );
    }

    public function test_the_attribute_alone_does_not_change_notification_send(): void
    {
        $responses = [];

        Event::listen(NotificationSent::class, function ($event) use (&$responses) {
            $responses[] = $event->response->getMessageId();
        });

        NotificationFacade::send($this->notifiables('taylor', 'dries'), new ParityNotification);

        $this->assertSame([], $this->transport->batches);
        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com'], $this->transport->singles);
        $this->assertSame(['id-taylor@laravel.com', 'id-dries@laravel.com'], $responses);
    }

    public function test_notifications_without_the_attribute_are_batched_with_the_mailers_defaults(): void
    {
        NotificationFacade::batch($this->notifiables(), new PlainNotification);

        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com']], $this->transport->batches);
    }

    public function test_notifications_that_should_not_be_sent_are_skipped(): void
    {
        $skipped = [];

        Event::listen(NotificationSkipped::class, function ($event) use (&$skipped) {
            $skipped[] = $event->notifiable->routeNotificationFor('mail');
        });

        Event::listen(NotificationSending::class, fn ($event) => $event->notifiable->routeNotificationFor('mail') !== 'james@laravel.com');

        NotificationFacade::batch($this->notifiables(), new ParityNotification(skip: 'dries@laravel.com'));

        $this->assertSame(['dries@laravel.com', 'james@laravel.com'], $skipped);
        $this->assertSame(['taylor@laravel.com'], $this->transport->singles);
    }

    public function test_each_notifiable_shares_one_id_across_channels_and_anonymous_notifiables_skip_the_database(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        $user = NotifiableUser::forceCreate(['email' => 'taylor@laravel.com']);

        $ids = [];

        Event::listen(NotificationSent::class, function ($event) use (&$ids) {
            $ids[$event->channel][] = $event->notification->id;
        });

        NotificationFacade::batch(
            [$user, (new AnonymousNotifiable)->route('mail', 'dries@laravel.com')],
            new DatabaseParityNotification,
        );

        $this->assertCount(1, $ids['database']);
        $this->assertCount(2, $ids['mail']);
        $this->assertSame($ids['database'][0], $ids['mail'][0]);
        $this->assertSame($ids['database'][0], $user->notifications()->first()->id);
    }

    public function test_notifications_are_sent_and_their_events_dispatched_in_the_notifiables_locale(): void
    {
        $locales = [];

        Event::listen(NotificationSent::class, function ($event) use (&$locales) {
            $locales[] = $this->app->getLocale();
        });

        NotificationFacade::batch([new LocalizedNotifiable('fr'), new LocalizedNotifiable('es')], new ParityNotification);
        NotificationFacade::locale('de')->batch([new LocalizedNotifiable('fr')], new ParityNotification);

        $this->assertSame(['fr', 'es', 'de'], $locales);
        $this->assertSame('en', $this->app->getLocale());
    }

    public function test_faked_notifications_are_recorded_as_sent(): void
    {
        NotificationFacade::fake();

        NotificationFacade::batch($this->notifiables('taylor'), new ParityNotification);
        NotificationFacade::batchNow($this->notifiables('dries'), new QueuedParityNotification);

        NotificationFacade::assertSentTimes(ParityNotification::class, 1);
        NotificationFacade::assertSentTimes(QueuedParityNotification::class, 1);
        $this->assertSame([], $this->transport->singles);
    }

    public function test_notifications_are_sent_unbatched_when_mail_is_faked(): void
    {
        Mail::fake();

        NotificationFacade::batch($this->notifiables(), new MailableParityNotification);

        // As with Notification::send(), the mail goes to the fake rather than the transport.
        $this->assertSame([], $this->transport->batches);
        $this->assertSame([], $this->transport->singles);
    }

    public function test_a_mail_channel_registered_by_another_package_is_used(): void
    {
        NotificationFacade::extend('mail', fn ($app) => $app->make(CustomMailChannel::class));

        NotificationFacade::batch($this->notifiables(), new ParityNotification(mailer: 'batched'));

        $this->assertSame(3, CustomMailChannel::$sends);
        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com']], $this->transport->batches);
    }

    public function test_queued_notifications_can_be_batched_now(): void
    {
        NotificationFacade::batchNow($this->notifiables(), new QueuedParityNotification);

        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com']], $this->transport->batches);
    }
}

#[BatchMail]
class ParityNotification extends Notification
{
    public function __construct(public ?string $skip = null, public ?string $mailer = null)
    {
        //
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function shouldSend($notifiable, $channel)
    {
        return $notifiable->routeNotificationFor('mail') !== $this->skip;
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Hello')->line('Hello!');
    }
}

class PlainNotification extends ParityNotification
{
    //
}

class QueuedParityNotification extends ParityNotification implements ShouldQueue
{
    use Queueable;
}

#[BatchMail]
class DatabaseParityNotification extends Notification
{
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Hello')->line('Hello!');
    }

    public function toArray($notifiable)
    {
        return ['hello' => true];
    }
}

#[BatchMail]
class MailableParityNotification extends Notification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new ParityMailable)->to($notifiable->routeNotificationFor('mail'));
    }
}

class ParityMailable extends Mailable
{
    public function build()
    {
        return $this->subject('Hello')->html('<p>Hello</p>');
    }
}

class LocalizedNotifiable extends AnonymousNotifiable implements HasLocalePreference
{
    public function __construct(public string $locale)
    {
        $this->route('mail', "{$locale}@laravel.com");
    }

    public function preferredLocale()
    {
        return $this->locale;
    }
}

class CustomMailChannel extends MailChannel
{
    public static int $sends = 0;

    public function send($notifiable, Notification $notification)
    {
        static::$sends++;

        return parent::send($notifiable, $notification);
    }
}
