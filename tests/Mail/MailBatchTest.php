<?php

namespace NineteenNinetyFour\MailBatch\Tests\Mail;

use Fiber;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use LogicException;
use NineteenNinetyFour\MailBatch\Exceptions\BatchListenerException;
use NineteenNinetyFour\MailBatch\Facades\MailBatch;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\RecordingBatchTransport;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\TestMailable;
use NineteenNinetyFour\MailBatch\Tests\TestCase;
use RuntimeException;

class MailBatchTest extends TestCase
{
    protected function mailables(string ...$names): array
    {
        return array_map(fn ($name) => new TestMailable("{$name}@laravel.com"), $names ?: ['taylor', 'dries', 'james']);
    }

    public function test_mailables_are_sent_in_one_request(): void
    {
        $result = Mail::batch($this->mailables());

        $this->assertSame([['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com']], $this->transport->batches);
        $this->assertCount(3, $result->sent());
        $this->assertSame(1, $result->requests());
        $this->assertTrue($result->successful());
    }

    public function test_the_facade_and_named_mailers_can_start_a_batch(): void
    {
        MailBatch::send($this->mailables('taylor'));
        Mail::mailer('batched')->batch($this->mailables('dries'));
        MailBatch::mailer('batched')->send($this->mailables('james'));

        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com'], $this->transport->singles);
    }

    public function test_messages_are_sent_one_at_a_time_when_the_transport_has_no_batch_api(): void
    {
        $result = Mail::mailer('array')->batch()->interval(250)->send($this->mailables());

        $this->assertCount(3, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(3, $result->requests());
        Sleep::assertSleptTimes(2);
    }

    public function test_messages_are_chunked_by_size_capped_at_the_transports_maximum(): void
    {
        $this->transport->max = 2;

        Mail::batch()->size(5)->send($this->mailables('a', 'b', 'c'));
        Mail::batch()->size(1)->send($this->mailables('d', 'e'));

        $this->assertSame([['a@laravel.com', 'b@laravel.com']], $this->transport->batches);
        $this->assertSame(['c@laravel.com', 'd@laravel.com', 'e@laravel.com'], $this->transport->singles);
    }

    public function test_options_are_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mail::batch()->size(0);
    }

    public function test_the_interval_is_applied_between_requests_only(): void
    {
        $this->transport->max = 1;

        Mail::batch()->perSecond(4)->send($this->mailables());

        Sleep::assertSequence([Sleep::usleep(250000), Sleep::usleep(250000)]);
    }

    public function test_defaults_are_read_from_the_mailers_config(): void
    {
        $this->app['config']->set('mail.mailers.batched.batch', ['size' => 2, 'per_second' => 2]);

        $batch = Mail::batch();

        $this->assertSame(2, $batch->getSize());
        $this->assertSame(500, $batch->getInterval());
    }

    public function test_messages_the_transport_cannot_batch_are_sent_on_their_own_in_order(): void
    {
        $this->transport->unbatchable = ['dries@laravel.com'];

        $result = Mail::batch($this->mailables('taylor', 'dries', 'james', 'nuno'));

        // "taylor" is a request of one, so it's sent with the transport's own "send" method.
        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com'], $this->transport->singles);
        $this->assertSame([['james@laravel.com', 'nuno@laravel.com']], $this->transport->batches);
        $this->assertSame([0, 1, 2, 3], array_keys($result->sent()));
    }

    public function test_per_message_failures_are_reported_and_only_sent_messages_get_sent_events(): void
    {
        $this->transport->rejected = ['dries@laravel.com'];

        $sent = [];

        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $event->message->getTo()[0]->getAddress();
        });

        $result = Mail::batch($this->mailables());

        $this->assertSame(['taylor@laravel.com', 'james@laravel.com'], $sent);
        $this->assertSame([1], array_keys($result->failures()));
        $this->assertTrue($result->failed());
    }

    public function test_sent_events_are_dispatched_after_the_request_with_the_message_data(): void
    {
        $events = [];

        Event::listen(MessageSent::class, function (MessageSent $event) use (&$events) {
            $events[] = [$event->data['mailer'] ?? null, $event->sent->getMessageId()];
        });

        $this->transport->onBatch = function () use (&$events) {
            $this->assertSame([], $events);
        };

        Mail::batch()->capture(function ($mailer) {
            $mailer->to('taylor@laravel.com')->send(new TestMailable('taylor@laravel.com'));
            $mailer->html('<p>Hi</p>', fn ($message) => $message->to('dries@laravel.com')->subject('Hi'));
        });

        $this->assertSame([['batched', 'id-taylor@laravel.com'], ['batched', 'id-dries@laravel.com']], $events);
    }

    public function test_failed_requests_are_retried_with_the_same_idempotency_key(): void
    {
        $this->transport->failRequests = 2;

        $result = Mail::batch()->retry(3, 100)->send($this->mailables('taylor', 'dries'));

        $this->assertTrue($result->successful());
        $this->assertSame(3, $result->requests());
        $this->assertCount(3, $this->transport->options);
        $this->assertCount(1, array_unique(array_column($this->transport->options, 'idempotency_key')));
    }

    public function test_every_message_in_a_failed_request_is_reported_as_failed(): void
    {
        $this->transport->failRequests = 1;

        $result = Mail::batch($this->mailables('taylor', 'dries'));

        $this->assertSame([0, 1], array_keys($result->failures()));
        $this->assertSame('Request failed.', $result->failures()[0]->getMessage());
    }

    public function test_nothing_is_sent_when_the_callback_throws(): void
    {
        try {
            Mail::batch()->capture(function ($mailer) {
                $mailer->send(new TestMailable);

                throw new RuntimeException('Oops.');
            });
        } catch (RuntimeException) {
            //
        }

        $this->assertSame([], $this->transport->batches);
        $this->assertSame([], $this->transport->singles);
        $this->assertInstanceOf(RecordingBatchTransport::class, Mail::mailer()->getSymfonyTransport());
    }

    public function test_batches_cannot_be_nested_on_the_same_mailer(): void
    {
        $this->expectException(LogicException::class);

        Mail::batch()->capture(fn () => Mail::batch()->capture(fn () => null));
    }

    public function test_batches_on_different_mailers_can_be_nested(): void
    {
        Mail::batch()->capture(function () {
            Mail::mailer('array')->batch($this->mailables('dries'));

            Mail::send(new TestMailable('taylor@laravel.com'));
        });

        $this->assertSame(['taylor@laravel.com'], $this->transport->singles);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_messages_cancelled_by_sending_listeners_are_not_captured(): void
    {
        Event::listen(MessageSending::class, fn (MessageSending $event) => $event->message->getTo()[0]->getAddress() !== 'dries@laravel.com');

        $result = Mail::batch($this->mailables());

        $this->assertCount(2, $result);
        $this->assertSame([['taylor@laravel.com', 'james@laravel.com']], $this->transport->batches);
    }

    public function test_only_mailables_can_be_sent_as_a_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mail::batch(['not a mailable']);
    }

    public function test_the_mailers_own_transport_is_put_back_after_the_batch(): void
    {
        Mail::mailer('array')->batch($this->mailables());

        $this->assertInstanceOf(ArrayTransport::class, Mail::mailer('array')->getSymfonyTransport());
    }

    public function test_mail_sent_from_another_fiber_while_a_batch_is_open_is_sent_normally(): void
    {
        Mail::batch()->capture(function ($mailer) {
            $mailer->send(new TestMailable('taylor@laravel.com'));

            (new Fiber(fn () => Mail::send(new TestMailable('dries@laravel.com'))))->start();

            $this->assertSame(['dries@laravel.com'], $this->transport->singles);
        });

        $this->assertSame(['dries@laravel.com', 'taylor@laravel.com'], $this->transport->singles);
    }

    public function test_a_throwing_sent_listener_does_not_stop_the_rest_of_the_batch(): void
    {
        $this->transport->max = 1;

        $sent = [];

        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $recipient = $event->message->getTo()[0]->getAddress();

            if ($recipient === 'taylor@laravel.com') {
                throw new RuntimeException('Listener failed.');
            }
        });

        try {
            Mail::batch($this->mailables());

            $this->fail('The listener exception was not thrown.');
        } catch (BatchListenerException $e) {
            $this->assertSame('Listener failed.', $e->getPrevious()->getMessage());
            $this->assertCount(3, $e->result->sent());
        }

        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com', 'james@laravel.com'], $sent);
    }
}
