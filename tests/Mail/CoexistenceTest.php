<?php

namespace NineteenNinetyFour\MailBatch\Tests\Mail;

use ArrayObject;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\TestMailable;
use NineteenNinetyFour\MailBatch\Tests\TestCase;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class CoexistenceTest extends TestCase
{
    public function test_laravels_services_are_not_replaced(): void
    {
        $this->assertSame(MailManager::class, get_class($this->app['mail.manager']));
        $this->assertSame(ChannelManager::class, get_class($this->app[ChannelManager::class]));
    }

    public function test_transports_wrapped_by_other_packages_are_batched_through_their_wrapper(): void
    {
        $sent = new ArrayObject;

        Mail::extend('wrapped', fn () => new class($this->transport, $sent) extends AbstractTransport
        {
            public function __construct(protected $inner, protected ArrayObject $sent)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                $this->sent[] = 'wrapped';

                $this->inner->send($message->getOriginalMessage(), $message->getEnvelope());
            }

            public function __toString(): string
            {
                return 'wrapped';
            }
        });

        $this->app['config']->set('mail.mailers.wrapped', ['transport' => 'wrapped']);

        $result = Mail::mailer('wrapped')->batch([new TestMailable('taylor@laravel.com'), new TestMailable('dries@laravel.com')]);

        // The wrapper has no batch API, so each message goes through it, and through Symfony's
        // own send path, one at a time.
        $this->assertSame(['wrapped', 'wrapped'], $sent->getArrayCopy());
        $this->assertSame(2, $result->requests());
        $this->assertSame(['taylor@laravel.com', 'dries@laravel.com'], $this->transport->singles);
    }
}
